<?php

namespace App\Services\Payments;

/** What the provider itself says about a payment, fetched server to server. */
final class ConfirmedPayment
{
    public function __construct(
        public readonly bool $paid,
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {}
}
