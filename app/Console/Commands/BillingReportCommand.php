<?php

namespace App\Console\Commands;

use App\Enums\ChargeOutcome;
use App\Models\InvocationCharge;
use App\Services\Billing\SettlementPolicy;
use App\Support\Billing\MicroUsd;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Shadow-mode pricing per pipeline step over a recent period: what steps really cost, what the
 * current settings would charge, and how much would be absorbed by holds that are too low.
 * This is what finance calibrates config/billing.php from — e.g. a step's hold at around its
 * p95 cost × markup.
 */
class BillingReportCommand extends Command
{
    protected $signature = 'boschifai:billing-report {--days=30 : How many days back to include}';

    protected $description = 'Per-step cost and (shadow) charges, for calibrating credit pricing';

    public function handle(SettlementPolicy $policy): int
    {
        $days = max(1, (int) $this->option('days'));
        $charges = InvocationCharge::where('created_at', '>=', now()->subDays($days))->get();

        $markup = MicroUsd::parseOptional(config('billing.markup')) === null
            ? 'not set (priced at cost)'
            : MicroUsd::format($policy->markupMillionths(), 2).'×';
        $this->line("Last {$days} days · markup {$markup} · minimum charge US$".MicroUsd::format($policy->minChargeMicro(), 2));

        if ($charges->isEmpty()) {
            $this->info('No priced steps in this period yet.');

            return self::SUCCESS;
        }

        $rows = $charges->groupBy('step_key')->sortKeys()->map(fn (Collection $step, string $key) => $this->row($key, $step, $policy));
        $this->table(
            ['Step', 'Runs', 'Charged / partial / free', 'Cost avg', 'Cost p95', 'Cost max', 'Hold', 'Charged', 'Absorbed'],
            $rows->values()->all(),
        );

        $cost = (int) $charges->sum('cost_micro');
        $charged = (int) $charges->sum('charge_micro');
        $this->line(sprintf('Total cost US$%s · charged US$%s · margin US$%s', $this->usd($cost), $this->usd($charged), $this->usd($charged - $cost)));

        return self::SUCCESS;
    }

    private function row(string $stepKey, Collection $step, SettlementPolicy $policy): array
    {
        $costs = $step->pluck('cost_micro')->filter(fn ($c) => $c !== null)->sort()->values();
        $hold = $policy->holdFor($stepKey);
        // What a hold cut off: the marked-up cost the customer wasn't charged.
        $absorbed = $step->where('outcome', ChargeOutcome::Charged)
            ->sum(fn (InvocationCharge $c) => max(0, MicroUsd::multiply((int) $c->cost_micro, $c->markup_millionths) - $c->charge_micro));

        return [
            $stepKey,
            $step->count(),
            implode(' / ', array_map(fn (ChargeOutcome $o) => $step->where('outcome', $o)->count(), [ChargeOutcome::Charged, ChargeOutcome::Partial, ChargeOutcome::Released])),
            $costs->isEmpty() ? '—' : $this->usd((int) round($costs->avg())),
            $costs->isEmpty() ? '—' : $this->usd($costs[(int) ceil(0.95 * $costs->count()) - 1]),
            $costs->isEmpty() ? '—' : $this->usd($costs->last()),
            $hold === null ? 'not set' : $this->usd($hold),
            $this->usd((int) $step->sum('charge_micro')),
            $this->usd((int) $absorbed),
        ];
    }

    private function usd(int $micro): string
    {
        return MicroUsd::format($micro, 4);
    }
}
