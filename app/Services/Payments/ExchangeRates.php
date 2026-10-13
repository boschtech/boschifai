<?php

namespace App\Services\Payments;

use App\Models\ExchangeRate;
use App\Support\Billing\MicroUsd;

/**
 * Which rate (units per US dollar) a checkout quotes with, in order:
 *
 * 1. An admin override (PAYMENTS_FX_{CUR}_RATE) — e.g. during a feed outage.
 * 2. The latest ECB reference rate, if it isn't older than payments.fx_feed.max_age_days.
 * 3. None: that currency isn't offered until a fresh rate is available — never an old one.
 */
class ExchangeRates
{
    /** @return array{rate: string, rate_millionths: int, source: string}|null */
    public function current(string $currency): ?array
    {
        $currency = strtoupper($currency);

        $override = config("payments.fx.{$currency}.rate");
        if (($millionths = MicroUsd::parseOptional($override)) !== null && $millionths > 0) {
            return ['rate' => trim((string) $override), 'rate_millionths' => $millionths, 'source' => 'admin'];
        }

        $latest = ExchangeRate::where('currency', $currency)
            ->where('published_on', '>=', now()->subDays((int) config('payments.fx_feed.max_age_days'))->toDateString())
            ->latest('published_on')
            ->first();

        return $latest ? [
            'rate' => $latest->rate,
            'rate_millionths' => MicroUsd::parse($latest->rate),
            'source' => "{$latest->source}:{$latest->published_on->toDateString()}",
        ] : null;
    }
}
