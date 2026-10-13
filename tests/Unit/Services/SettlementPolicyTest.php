<?php

namespace Tests\Unit\Services;

use App\Enums\ChargeOutcome;
use App\Services\Billing\SettlementPolicy;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettlementPolicyTest extends TestCase
{
    private function invocation(int $exitCode = 0, bool $timedOut = false, bool $cancelled = false, ?string $error = null): ClaudeInvocationResult
    {
        return new ClaudeInvocationResult(exitCode: $exitCode, timedOut: $timedOut, rawTranscriptPath: '', durationMs: 1, totalCostUsd: 0.5, numTurns: 1, stopReason: null, stderr: '', cancelled: $cancelled, resultError: $error);
    }

    public static function outcomes(): array
    {
        return [
            'finished' => [[], ChargeOutcome::Charged],
            'ran out of turns (its own work)' => [['exitCode' => 1, 'error' => 'Claude reached its maximum number of turns before finishing.'], ChargeOutcome::Charged],
            'stopped by the user' => [['cancelled' => true, 'exitCode' => -1], ChargeOutcome::Partial],
            'timed out' => [['timedOut' => true, 'exitCode' => -1], ChargeOutcome::Released],
            'CLI crashed without a result' => [['exitCode' => 3], ChargeOutcome::Released],
            'platform out of credit' => [['exitCode' => 1, 'error' => 'The Anthropic API account used by BoschifAI has run out of credit. (Claude API error (HTTP 400): Your credit balance is too low)'], ChargeOutcome::Released],
            'platform key rejected' => [['exitCode' => 1, 'error' => 'Claude API error (HTTP 401): invalid x-api-key'], ChargeOutcome::Released],
            'rate limited' => [['exitCode' => 1, 'error' => 'Claude API error (HTTP 429): rate limit'], ChargeOutcome::Released],
            'overloaded' => [['exitCode' => 1, 'error' => 'Claude API error (HTTP 529): Overloaded'], ChargeOutcome::Released],
            'server error' => [['exitCode' => 1, 'error' => 'Claude API error (HTTP 500): internal'], ChargeOutcome::Released],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_who_pays_depends_on_how_the_step_ended(array $result, ChargeOutcome $outcome): void
    {
        $this->assertSame($outcome, app(SettlementPolicy::class)->outcomeFor($this->invocation(...$result)));
    }

    public function test_charges_are_cost_times_markup_at_least_the_minimum_and_never_above_the_hold(): void
    {
        config(['billing.markup' => '1.5', 'billing.min_charge_usd' => '0.05']);
        $policy = app(SettlementPolicy::class);

        $this->assertSame(1_500_000, $policy->chargeFor(ChargeOutcome::Charged, 1_000_000, null));
        $this->assertSame(50_000, $policy->chargeFor(ChargeOutcome::Charged, 10_000, null), 'minimum');
        $this->assertSame(50_000, $policy->chargeFor(ChargeOutcome::Charged, null, null), 'no cost reported: minimum');
        $this->assertSame(1_200_000, $policy->chargeFor(ChargeOutcome::Charged, 1_000_000, 1_200_000), 'capped by the hold');
        $this->assertSame(400_000, $policy->chargeFor(ChargeOutcome::Partial, 400_000, null), 'partial: at cost, no markup or minimum');
        $this->assertSame(300_000, $policy->chargeFor(ChargeOutcome::Partial, 400_000, 300_000));
        $this->assertSame(0, $policy->chargeFor(ChargeOutcome::Partial, null, null));
        $this->assertSame(0, $policy->chargeFor(ChargeOutcome::Released, 1_000_000, null));
    }

    public function test_unset_figures_mean_at_cost_no_minimum_and_no_hold(): void
    {
        config(['billing.markup' => null, 'billing.min_charge_usd' => '', 'billing.stage_holds_usd.gap_analysis' => null]);
        $policy = app(SettlementPolicy::class);

        $this->assertSame(1_000_000, $policy->markupMillionths());
        $this->assertSame(0, $policy->minChargeMicro());
        $this->assertNull($policy->holdFor('gap_analysis'));
        $this->assertNull($policy->holdFor('not_a_step'));
        $this->assertSame(123_400, $policy->chargeFor(ChargeOutcome::Charged, 123_400, null));

        config(['billing.stage_holds_usd.gap_analysis' => '2.50']);
        $this->assertSame(2_500_000, $policy->holdFor('gap_analysis'));
    }
}
