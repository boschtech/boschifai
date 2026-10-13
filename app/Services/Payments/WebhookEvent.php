<?php

namespace App\Services\Payments;

final class WebhookEvent
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    /**
     * @param  string|null  $outcome  self::PAID, self::FAILED, or null for events that don't
     *                                change a payment
     * @param  array<string, mixed>  $redactedPayload  stored as-is: no card, buyer or secret data
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?string $providerRef,
        public readonly ?string $outcome,
        public readonly array $redactedPayload = [],
    ) {}
}
