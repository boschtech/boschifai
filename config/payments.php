<?php

/*
| Buying credits. Each provider (Stripe, Paystack, PayFast) is an adapter behind
| App\Services\Payments\PaymentProvider; which one ships first is an open decision (see
| docs/outstanding-decisions.md D1). Provider secrets come from the environment / AWS SSM —
| never commit them.
*/
return [

    // Providers customers can pay with, e.g. "stripe,payfast". A provider must also be
    // implemented (registered in PaymentProviderRegistry) to be offered.
    'enabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMENTS_ENABLED', ''))))),

    'providers' => [
        // https://paystack.com — a South African business can charge and settle in ZAR only.
        // Webhook URL to set in the Paystack dashboard: {APP_URL}/webhooks/payments/paystack
        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'currencies' => array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', (string) env('PAYSTACK_CURRENCIES', 'ZAR'))))),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        ],
    ],

    // Currencies other than USD that customers can pay in (credits are always USD). The customer
    // is quoted price × rate × (1 + buffer), rounded up to the cent, and the quote — with the rate
    // and its source — is stored with the payment (docs/outstanding-decisions.md D3).
    //   rate:   optional admin override (units per US dollar); normally empty, so the latest ECB
    //           reference rate is used (see fx_feed)
    //   buffer: added on top of the rate, e.g. 0.15 = 15%
    'fx' => [
        'ZAR' => [
            'rate' => env('PAYMENTS_FX_ZAR_RATE'),
            'buffer' => env('PAYMENTS_FX_ZAR_BUFFER', '0.15'),
        ],
    ],

    // The European Central Bank's daily reference rates, fetched by
    // `php artisan boschifai:refresh-exchange-rates` (run daily).
    'fx_feed' => [
        'ecb_url' => env('PAYMENTS_FX_ECB_URL', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'),
        // Older than this (weekends and holidays have no new rate) and the currency isn't offered.
        'max_age_days' => (int) env('PAYMENTS_FX_MAX_AGE_DAYS', 4),
        // A day-to-day move larger than this (0.05 = 5%) isn't used — likely a feed error.
        'max_change' => env('PAYMENTS_FX_MAX_CHANGE', '0.05'),
    ],

];
