<?php

namespace Tests\Feature\Billing;

use App\Enums\ChargeOutcome;
use App\Services\Billing\SettlementPolicy;
use Tests\TestCase;

/** The default pricing (D4) — used whenever the BILLING_* settings are empty, as phpunit.xml sets them. */
class DefaultPricingTest extends TestCase
{
    public function test_steps_are_charged_at_twice_cost_with_a_two_cent_minimum(): void
    {
        $policy = app(SettlementPolicy::class);

        $this->assertSame(2_000_000, $policy->markupMillionths());
        $this->assertSame(20_000, $policy->minChargeMicro());
        $this->assertSame(1_000_000, $policy->chargeFor(ChargeOutcome::Charged, 500_000, null), 'US$0.50 of usage → US$1.00');
        $this->assertSame(20_000, $policy->chargeFor(ChargeOutcome::Charged, 1_000, null), 'a tiny step pays the minimum');
    }

    public function test_holds_cover_the_largest_cost_seen_at_that_markup_and_fit_in_a_starter_pack(): void
    {
        $holds = config('billing.stage_holds_usd');

        $this->assertSame([
            'gap_analysis' => '2', 'test_plan' => '3', 'test_case_generation' => '2', 'code_generation' => '9',
            'fix_failing_tests' => '3', 'push' => '1', 'build_skills' => '2', 'build_knowledge_base' => '4',
        ], $holds);

        $starter = (int) config('billing.packs.starter.credits_usd');
        foreach ($holds as $step => $hold) {
            $this->assertLessThanOrEqual($starter, (int) $hold, "{$step}: one Starter pack must cover its hold");
        }
    }

    public function test_a_set_value_wins_and_an_empty_one_keeps_the_default(): void
    {
        $load = function (array $env) {
            foreach ($env as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
            try {
                return require config_path('billing.php');
            } finally {
                foreach (array_keys($env) as $key) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                }
            }
        };

        $this->assertSame('1.5', $load(['BILLING_MARKUP' => '1.5'])['markup']);
        $this->assertSame('0', $load(['BILLING_MIN_CHARGE_USD' => '0'])['min_charge_usd'], '"0" is a real value, not "unset"');
        $this->assertSame('2.0', $load(['BILLING_MARKUP' => ''])['markup']);
    }
}
