<?php

namespace App\Services\Billing;

use App\Models\ClaudeInvocation;
use App\Models\InvocationCharge;
use App\Models\Run;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Support\Billing\MicroUsd;

/**
 * Prices each finished Claude step. Shadow mode for now: the charge is recorded
 * (invocation_charges) but no credits move — that's what calibrates holds and markup before
 * anyone is billed.
 */
class StepBilling
{
    public function __construct(private SettlementPolicy $policy) {}

    public function recordShadowCharge(Run $run, string $stepKey, ClaudeInvocation $invocation, ClaudeInvocationResult $result): InvocationCharge
    {
        $outcome = $this->policy->outcomeFor($result);
        $cost = $result->totalCostUsd === null ? null : MicroUsd::parse($result->totalCostUsd);
        $hold = $this->policy->holdFor($stepKey);

        return InvocationCharge::create([
            'workspace_id' => $run->workspace_id,
            'claude_invocation_id' => $invocation->id,
            'step_key' => $stepKey,
            'outcome' => $outcome,
            'cost_micro' => $cost,
            'hold_micro' => $hold,
            'charge_micro' => $this->policy->chargeFor($outcome, $cost, $hold),
            'markup_millionths' => $this->policy->markupMillionths(),
            'enforced' => false,
        ]);
    }
}
