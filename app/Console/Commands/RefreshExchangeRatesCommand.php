<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Services\Payments\EcbReferenceRates;
use App\Support\Billing\MicroUsd;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fetches today's ECB reference rates for the currencies customers can pay in (the keys of
 * payments.fx). A rate that moved more than payments.fx_feed.max_change since the last one is
 * NOT stored — more likely a feed error than the market — and the command fails so it's noticed;
 * the previous rate stays in use until it's too old. Run daily (e.g. cron at 17:00 CET, after
 * the ECB publishes); see docs/outstanding-decisions.md D15 for scheduling.
 */
class RefreshExchangeRatesCommand extends Command
{
    protected $signature = 'boschifai:refresh-exchange-rates';

    protected $description = 'Fetch the latest ECB reference exchange rates for checkout quotes';

    public function handle(EcbReferenceRates $ecb): int
    {
        $currencies = array_values(array_diff(array_keys(config('payments.fx', [])), ['USD']));

        try {
            $feed = $ecb->fetch($currencies);
        } catch (RuntimeException $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $maxChange = MicroUsd::parseOptional(config('payments.fx_feed.max_change')) ?? 0;
        $ok = true;
        foreach ($currencies as $currency) {
            $rate = $feed['rates'][$currency] ?? null;
            if ($rate === null) {
                $this->warn("{$currency}: not in the ECB feed.");
                $ok = false;

                continue;
            }

            $previous = ExchangeRate::where('currency', $currency)->where('source', EcbReferenceRates::SOURCE)->latest('published_on')->first();
            if ($previous && $maxChange > 0) {
                $before = MicroUsd::parse($previous->rate);
                $change = intdiv(abs(MicroUsd::parse($rate) - $before) * MicroUsd::PER_USD, $before);
                if ($change > $maxChange) {
                    $message = sprintf('%s: the ECB rate moved from %s to %s (%s%%) — not used; check it, or set PAYMENTS_FX_%s_RATE by hand.', $currency, $previous->rate, $rate, MicroUsd::format($change * 100, 1), $currency);
                    report(new RuntimeException($message));
                    $this->error($message);
                    $ok = false;

                    continue;
                }
            }

            // Matched on the date alone: databases store a date column differently.
            $known = ExchangeRate::where('currency', $currency)->where('source', EcbReferenceRates::SOURCE)
                ->whereDate('published_on', $feed['published_on'])->exists();
            if (! $known) {
                ExchangeRate::create(['currency' => $currency, 'source' => EcbReferenceRates::SOURCE, 'published_on' => $feed['published_on'], 'rate' => $rate]);
            }
            $this->info("{$currency}: {$rate} per US dollar (ECB, {$feed['published_on']}).");
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
