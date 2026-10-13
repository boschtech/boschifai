<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Services\Pipeline\StepDispatcher;

/**
 * Refuses up front (HTTP 402, see bootstrap/app.php) to start a step the workspace can't afford,
 * so people find out when they click rather than when the queued job reaches it. The hold taken
 * when the step actually runs (StepBilling::begin) remains the real check.
 */
class CreditGate
{
    public function __construct(
        private SettlementPolicy $policy,
        private CreditLedger $ledger,
        private StepDispatcher $steps,
    ) {}

    /** @throws InsufficientCredits */
    public function assertCanStart(Workspace $workspace, string $stepKey): void
    {
        if (! config('billing.enforce') || ! $this->steps->usesClaude($stepKey)) {
            return;
        }

        $hold = $this->policy->holdFor($stepKey);
        $available = $this->ledger->balance($workspace)->available_micro;
        if ($hold !== null && $hold > $available) {
            throw new InsufficientCredits($available, $hold);
        }
    }
}
