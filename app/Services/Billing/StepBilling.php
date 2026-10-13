<?php

namespace App\Services\Billing;

use App\Models\ClaudeInvocation;
use App\Models\CreditLedgerEntry;
use App\Models\InvocationCharge;
use App\Models\Run;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Support\Billing\MicroUsd;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Prices each Claude step and, when billing is enforced, moves the credits:
 *
 *   begin()   reserves the step's hold before it runs (throws InsufficientCredits if the
 *             workspace can't cover it)
 *   finish()  records the charge and settles the hold — the charge is taken, the rest returned
 *   abandon() returns the whole hold when the step crashed before finishing
 *
 * In shadow mode (billing.enforce off) begin() reserves nothing and finish() only records what
 * would have been charged.
 */
class StepBilling
{
    public function __construct(
        private SettlementPolicy $policy,
        private CreditLedger $ledger,
    ) {}

    public function enforced(): bool
    {
        return (bool) config('billing.enforce');
    }

    /** @throws InsufficientCredits */
    public function begin(Run $run, string $stepKey): ?CreditLedgerEntry
    {
        if (! $this->enforced()) {
            return null;
        }

        $hold = $this->policy->holdFor($stepKey);
        if ($hold === null || $hold <= 0) {
            // A configuration gap on our side: let the customer's step run (uncharged) rather
            // than block them, and make sure someone finds out.
            report(new RuntimeException("Billing is enforced but no hold is set for step \"{$stepKey}\" (BILLING_HOLD_".Str::upper($stepKey).'_USD); it ran without being charged.'));

            return null;
        }

        return $this->ledger->hold($run->workspace, $hold, 'hold:'.Str::uuid(), $run);
    }

    public function finish(Run $run, string $stepKey, ClaudeInvocation $invocation, ClaudeInvocationResult $result, ?CreditLedgerEntry $hold): InvocationCharge
    {
        $outcome = $this->policy->outcomeFor($result);
        $cost = $result->totalCostUsd === null ? null : MicroUsd::parse($result->totalCostUsd);
        $holdMicro = $hold?->held_delta_micro ?? $this->policy->holdFor($stepKey);
        $charge = $this->policy->chargeFor($outcome, $cost, $holdMicro);

        $chargeEntry = $hold ? $this->ledger->settle($hold, $charge) : null;

        return InvocationCharge::create([
            'workspace_id' => $run->workspace_id,
            'claude_invocation_id' => $invocation->id,
            'step_key' => $stepKey,
            'outcome' => $outcome,
            'cost_micro' => $cost,
            'hold_micro' => $holdMicro,
            'charge_micro' => $charge,
            'markup_millionths' => $this->policy->markupMillionths(),
            'enforced' => $hold !== null,
            'ledger_entry_id' => $chargeEntry?->id,
        ]);
    }

    public function abandon(?CreditLedgerEntry $hold): void
    {
        if ($hold) {
            $this->ledger->release($hold);
        }
    }
}
