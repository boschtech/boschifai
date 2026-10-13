<?php

namespace App\Services\Billing;

use App\Enums\CreditEntryType;
use App\Models\ClaudeInvocation;
use App\Models\CreditLedgerEntry;
use App\Models\CreditLot;
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
 *
 * Credits come in lots (CreditLot), one per purchase or grant, each expiring
 * billing.credit_validity_months after it was added. Charges use up the lots that expire soonest;
 * expire() removes what's left of a lot past its date — but never credits currently held for a
 * running step (those expire once the hold is settled, if still unused). The lots always add up
 * to the available + held balance (isConsistent()).
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

        return DB::transaction(function () use ($workspace, $micro, $actor, $reason, $idempotencyKey) {
            $entry = $this->apply($workspace->id, CreditEntryType::Adjustment, $micro, 0, $idempotencyKey, [
                'actor_user_id' => $actor?->id,
                'reason' => trim($reason),
            ]);
            if ($entry->wasRecentlyCreated) {
                $micro > 0 ? $this->addLot($entry, 'adjustment') : $this->useOldestCredits($workspace->id, -$micro);
            }

            return $entry;
        });
    }

    /** Grants a confirmed payment's credits. Repeating it for the same payment grants nothing more. */
    public function purchase(Payment $payment): CreditLedgerEntry
    {
        if ($payment->credits_micro <= 0) {
            throw new InvalidArgumentException('A purchase must add credits.');
        }

        return DB::transaction(function () use ($payment) {
            $entry = $this->apply($payment->workspace_id, CreditEntryType::Purchase, $payment->credits_micro, 0, "purchase:{$payment->id}", [
                'actor_user_id' => $payment->created_by_user_id,
                'meta' => ['payment_id' => $payment->id, 'provider' => $payment->provider],
            ]);
            if ($entry->wasRecentlyCreated) {
                $this->addLot($entry, 'purchase');
            }

            return $entry;
        });
    }

    /**
     * Removes what's left of an expired lot from the available balance. Credits of it that are
     * held for a running step are left alone (they're used or expire once the hold settles).
     * Returns the amount expired (0 when there was nothing available to expire).
     */
    public function expire(CreditLot $lot): int
    {
        return DB::transaction(function () use ($lot) {
            $lot = CreditLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if ($lot->remaining_micro <= 0 || $lot->expires_at === null || $lot->expires_at->isFuture()) {
                return 0;
            }

            $amount = min($lot->remaining_micro, $this->balance(Workspace::findOrFail($lot->workspace_id))->available_micro);
            if ($amount <= 0) {
                return 0;
            }

            $this->apply($lot->workspace_id, CreditEntryType::Expiry, -$amount, 0, "expiry:{$lot->id}:{$lot->remaining_micro}", [
                'reason' => 'Credits expired '.$lot->expires_at->toDateString().'.',
                'meta' => ['credit_lot_id' => $lot->id],
            ]);
            $lot->update(['remaining_micro' => $lot->remaining_micro - $amount]);

            return $amount;
        });
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
            if ($charge) {
                $this->useOldestCredits($hold->workspace_id, $chargeMicro);
            }
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

        $inLots = (int) CreditLot::where('workspace_id', $workspace->id)->sum('remaining_micro');

        return (int) $sums->available === $balance->available_micro && (int) $sums->held === $balance->held_micro
            && $inLots === $balance->available_micro + $balance->held_micro;
    }

    private function addLot(CreditLedgerEntry $entry, string $kind): void
    {
        CreditLot::create([
            'workspace_id' => $entry->workspace_id,
            'ledger_entry_id' => $entry->id,
            'kind' => $kind,
            'amount_micro' => $entry->available_delta_micro,
            'remaining_micro' => $entry->available_delta_micro,
            'expires_at' => now()->addMonths((int) config('billing.credit_validity_months')),
        ]);
    }

    /** Credits actually spent come out of the lots that expire soonest. */
    private function useOldestCredits(int $workspaceId, int $micro): void
    {
        $lots = CreditLot::where('workspace_id', $workspaceId)->where('remaining_micro', '>', 0)
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id')
            ->lockForUpdate()->get();

        foreach ($lots as $lot) {
            if ($micro <= 0) {
                break;
            }
            $used = min($micro, $lot->remaining_micro);
            $lot->update(['remaining_micro' => $lot->remaining_micro - $used]);
            $micro -= $used;
        }
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
