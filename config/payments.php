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

    // Exchange rates for providers that charge in a currency other than USD (credits are always
    // USD). The customer is quoted price × rate × (1 + buffer), rounded up to the cent, and that
    // quote is stored with the payment. Rate source, refresh and buffer are a finance decision
    // (docs/outstanding-decisions.md D3) — a currency without a rate can't be charged.
    'fx' => [
        'ZAR' => [
            'rate' => env('PAYMENTS_FX_ZAR_RATE'),
            'buffer' => env('PAYMENTS_FX_ZAR_BUFFER', '0'),
            'source' => env('PAYMENTS_FX_ZAR_SOURCE', 'admin'),
        ],
    ],

];
