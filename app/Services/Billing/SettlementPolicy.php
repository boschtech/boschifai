<?php

namespace App\Services\Billing;

use App\Enums\ChargeOutcome;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Support\Billing\MicroUsd;

/**
 * What a Claude step costs the customer. Figures come from config/billing.php.
 *
 * - Finished, or failed on its own work (e.g. ran out of turns): cost × markup, at least the
 *   minimum charge.
 * - Stopped by the user: what was actually used, at cost.
 * - The platform's fault — the Anthropic API refusing or failing (bad key, out of credit, rate
 *   limit, overload, 5xx), a timeout, or the CLI crashing: nothing.
 *
 * The charge never exceeds the step's hold: the customer is never charged more than was
 * reserved, and the platform absorbs any overage.
 */
class SettlementPolicy
{
    /** API failures that are ours to fix, not the customer's (see ClaudeTranscriptParser). */
    private const PLATFORM_FAULT = '/Claude API error \(HTTP (?:401|403|429|5\d\d)\)|credit balance is too low|overloaded|rate limit/i';

    public function outcomeFor(ClaudeInvocationResult $result): ChargeOutcome
    {
        return match (true) {
            $result->cancelled => ChargeOutcome::Partial,
            $result->timedOut => ChargeOutcome::Released,
            $result->succeeded() => ChargeOutcome::Charged,
            $result->resultError !== null && ! preg_match(self::PLATFORM_FAULT, $result->resultError) => ChargeOutcome::Charged,
            default => ChargeOutcome::Released,
        };
    }

    public function chargeFor(ChargeOutcome $outcome, ?int $costMicro, ?int $holdMicro): int
    {
        $charge = match ($outcome) {
            ChargeOutcome::Released => 0,
            ChargeOutcome::Partial => $costMicro ?? 0,
            ChargeOutcome::Charged => max($this->minChargeMicro(), $this->withMarkup($costMicro ?? 0)),
        };

        return $holdMicro === null ? $charge : min($charge, $holdMicro);
    }

    public function withMarkup(int $costMicro): int
    {
        return MicroUsd::multiply($costMicro, $this->markupMillionths());
    }

    public function holdFor(string $stepKey): ?int
    {
        return MicroUsd::parseOptional(config("billing.stage_holds_usd.{$stepKey}"));
    }

    /** The markup as millionths: 1.5 → 1 500 000. Unset = 1.0 (at cost). */
    public function markupMillionths(): int
    {
        return MicroUsd::parseOptional(config('billing.markup')) ?? MicroUsd::PER_USD;
    }

    public function minChargeMicro(): int
    {
        return MicroUsd::parseOptional(config('billing.min_charge_usd')) ?? 0;
    }
}
