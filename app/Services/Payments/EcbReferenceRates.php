<?php

namespace App\Services\Payments;

use App\Support\Billing\MicroUsd;
use DOMDocument;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The European Central Bank's euro foreign-exchange reference rates — free and official,
 * published once per working day (around 16:00 CET). The ECB publishes them "for information
 * purposes only": fine as a reference with a buffer on top, not a dealing rate.
 *
 * The feed is per euro, so a rate per US dollar is the cross rate: (currency per EUR) / (USD per EUR).
 */
class EcbReferenceRates
{
    public const SOURCE = 'ecb';

    /**
     * @param  list<string>  $currencies  ISO codes wanted, e.g. ["ZAR"]
     * @return array{published_on: string, rates: array<string, string>} rates per US dollar, 6 dp
     *
     * @throws RuntimeException when the feed can't be fetched or read
     */
    public function fetch(array $currencies): array
    {
        $response = Http::timeout(20)->get((string) config('payments.fx_feed.ecb_url'));
        if (! $response->successful()) {
            throw new RuntimeException("The ECB rates feed answered HTTP {$response->status()}.");
        }

        $document = new DOMDocument;
        // No network access and no external entities while parsing a feed from the internet.
        if (! @$document->loadXML($response->body(), LIBXML_NONET)) {
            throw new RuntimeException('The ECB rates feed could not be read.');
        }

        $date = null;
        $perEuro = [];
        foreach ($document->getElementsByTagName('Cube') as $cube) {
            if ($cube->hasAttribute('time')) {
                $date = $cube->getAttribute('time');
            }
            if ($cube->hasAttribute('currency') && $cube->hasAttribute('rate')) {
                $perEuro[strtoupper($cube->getAttribute('currency'))] = $cube->getAttribute('rate');
            }
        }

        if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || empty($perEuro['USD'])) {
            throw new RuntimeException('The ECB rates feed had no date or no US dollar rate.');
        }

        $usdPerEuro = MicroUsd::parse($perEuro['USD']);
        $rates = [];
        foreach ($currencies as $currency) {
            if (isset($perEuro[$currency])) {
                // Millionths throughout, rounded half up: (currency/EUR) ÷ (USD/EUR).
                $rates[$currency] = MicroUsd::format(intdiv(MicroUsd::parse($perEuro[$currency]) * MicroUsd::PER_USD + intdiv($usdPerEuro, 2), $usdPerEuro));
            }
        }

        return ['published_on' => $date, 'rates' => $rates];
    }
}
