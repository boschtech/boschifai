<?php

namespace App\Services\Payments;

/**
 * The implemented providers. A provider is offered to customers only when it is registered here
 * AND listed in payments.enabled.
 */
class PaymentProviderRegistry
{
    /** @var array<string, PaymentProvider> */
    private array $providers = [];

    public function register(PaymentProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function find(string $key): ?PaymentProvider
    {
        return $this->providers[$key] ?? null;
    }

    public function isEnabled(string $key): bool
    {
        return isset($this->providers[$key]) && in_array($key, config('payments.enabled', []), true);
    }

    /** @return list<PaymentProvider> */
    public function enabled(): array
    {
        return array_values(array_filter($this->providers, fn (PaymentProvider $p) => $this->isEnabled($p->key())));
    }
}
