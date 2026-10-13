<?php

namespace App\Services\Billing;

use App\Enums\CreditEntryType;
use App\Models\ClaudeInvocation;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\Run;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCreditBalance;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only way credits move. Each movement is one append-only ledger entry plus the matching
 * change to the workspace's cached balance, in one transaction with the balance row locked, so
 * the balance always equals the sum of the entries (see isConsistent()).
 *
 * Every movement carries an idempotency key: repeating a call with the same key (a retried
 * job, a payment webhook delivered twice) returns the original entry instead of moving credits
 * again. Reusing a key for a different movement is a bug and throws.
 *
 * Available credits never go below zero: a hold or debit that doesn't fit throws
 * InsufficientCredits and records nothing.
 */
class CreditLedger
{
    public function balance(Workspace $workspace): WorkspaceCreditBalance
    {
        return WorkspaceCreditBalance::find($workspace->id)
            ?? new WorkspaceCreditBalance(['workspace_id' => $workspace->id, 'available_micro' => 0, 'held_micro' => 0]);
    }

    /** A platform admin's manual credit (positive) or debit (negative), with the reason. */
    public function adjust(Workspace $workspace, int $micro, ?User $actor, string $reason, string $idempotencyKey): CreditLedgerEntry
    {
        if ($micro === 0) {
            throw new InvalidArgumentException('An adjustment must change the balance.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An adjustment needs a reason.');
        }

        return $this->apply($workspace->id, CreditEntryType::Adjustment, $micro, 0, $idempotencyKey, [
            'actor_user_id' => $actor?->id,
            'reason' => trim($reason),
        ]);
    }

    /** Grants a confirmed payment's credits. Repeating it for the same payment grants nothing more. */
    public function purchase(Payment $payment): CreditLedgerEntry
    {
        if ($payment->credits_micro <= 0) {
            throw new InvalidArgumentException('A purchase must add credits.');
        }

        return $this->apply($payment->workspace_id, CreditEntryType::Purchase, $payment->credits_micro, 0, "purchase:{$payment->id}", [
            'actor_user_id' => $payment->created_by_user_id,
            'meta' => ['payment_id' => $payment->id, 'provider' => $payment->provider],
        ]);
    }

    /** Reserves credits for a step about to run. */
    public function hold(Workspace $workspace, int $micro, string $idempotencyKey, ?Run $run = null, ?ClaudeInvocation $invocation = null): CreditLedgerEntry
    {
        if ($micro <= 0) {
            throw new InvalidArgumentException('A hold must be for a positive amount.');
        }

        return $this->apply($workspace->id, CreditEntryType::Hold, -$micro, $micro, $idempotencyKey, [
            'run_id' => $run?->id,
            'claude_invocation_id' => $invocation?->id,
        ]);
    }

    /**
     * Charges $chargeMicro from a hold and returns the rest to the available balance. Returns
     * the charge entry, or null when nothing was charged. Settling the same hold again returns
     * the original result; settling it for a different amount throws.
     */
    public function settle(CreditLedgerEntry $hold, int $chargeMicro): ?CreditLedgerEntry
    {
        if ($hold->type !== CreditEntryType::Hold) {
            throw new LogicException('Only a hold can be settled.');
        }
        $held = $hold->held_delta_micro;
        if ($chargeMicro < 0 || $chargeMicro > $held) {
            throw new InvalidArgumentException('A charge must be between zero and the amount held.');
        }

        return DB::transaction(function () use ($hold, $held, $chargeMicro) {
            $previousCharge = CreditLedgerEntry::where('idempotency_key', "charge:{$hold->id}")->first();
            $previouslySettled = $previousCharge || CreditLedgerEntry::where('idempotency_key', "release:{$hold->id}")->exists();
            if ($previouslySettled) {
                if (-($previousCharge?->held_delta_micro ?? 0) !== $chargeMicro) {
                    throw new LogicException("Hold {$hold->id} was already settled for a different amount.");
                }

                return $previousCharge;
            }

            $related = ['related_entry_id' => $hold->id, 'run_id' => $hold->run_id, 'claude_invocation_id' => $hold->claude_invocation_id];
            $charge = $chargeMicro > 0
                ? $this->apply($hold->workspace_id, CreditEntryType::Charge, 0, -$chargeMicro, "charge:{$hold->id}", $related)
                : null;
            if ($held - $chargeMicro > 0) {
                $this->apply($hold->workspace_id, CreditEntryType::Release, $held - $chargeMicro, -($held - $chargeMicro), "release:{$hold->id}", $related);
            }

            return $charge;
        });
    }

    /** Returns a whole hold to the available balance (nothing charged). */
    public function release(CreditLedgerEntry $hold): void
    {
        $this->settle($hold, 0);
    }

    /** Whether the cached balance still equals the sum of the workspace's ledger entries. */
    public function isConsistent(Workspace $workspace): bool
    {
        $sums = CreditLedgerEntry::where('workspace_id', $workspace->id)
            ->selectRaw('COALESCE(SUM(available_delta_micro), 0) as available, COALESCE(SUM(held_delta_micro), 0) as held')
            ->first();
        $balance = $this->balance($workspace);

        return (int) $sums->available === $balance->available_micro && (int) $sums->held === $balance->held_micro;
    }

    /** @param  array<string, mixed>  $attributes */
    private function apply(int $workspaceId, CreditEntryType $type, int $availableDelta, int $heldDelta, string $idempotencyKey, array $attributes): CreditLedgerEntry
    {
        return DB::transaction(function () use ($workspaceId, $type, $availableDelta, $heldDelta, $idempotencyKey, $attributes) {
            // Locking the balance row serialises every movement for this workspace.
            WorkspaceCreditBalance::insertOrIgnore(['workspace_id' => $workspaceId, 'available_micro' => 0, 'held_micro' => 0, 'updated_at' => now()]);
            $balance = WorkspaceCreditBalance::whereKey($workspaceId)->lockForUpdate()->first();

            $existing = CreditLedgerEntry::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $same = $existing->workspace_id === $workspaceId && $existing->type === $type
                    && $existing->available_delta_micro === $availableDelta && $existing->held_delta_micro === $heldDelta;
                if (! $same) {
                    throw new LogicException("Idempotency key \"{$idempotencyKey}\" was already used for a different movement.");
                }

                return $existing;
            }

            if ($availableDelta < 0 && $balance->available_micro + $availableDelta < 0) {
                throw new InsufficientCredits($balance->available_micro, -$availableDelta);
            }

            $entry = CreditLedgerEntry::create([
                'workspace_id' => $workspaceId,
                'type' => $type,
                'available_delta_micro' => $availableDelta,
                'held_delta_micro' => $heldDelta,
                'idempotency_key' => $idempotencyKey,
                ...$attributes,
            ]);

            $balance->update([
                'available_micro' => $balance->available_micro + $availableDelta,
                'held_micro' => $balance->held_micro + $heldDelta,
            ]);

            return $entry;
        });
    }
}
