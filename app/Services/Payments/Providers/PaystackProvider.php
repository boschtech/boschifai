<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\ConfirmedPayment;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\WebhookEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Paystack (https://paystack.com/docs). For a South African business it charges and settles in
 * ZAR only (payments.providers.paystack.currencies). Hosted checkout via Transaction Initialize;
 * webhooks signed with HMAC-SHA512 of the raw body keyed with the secret key
 * (x-paystack-signature); every payment confirmed with Transaction Verify before credits are
 * granted (see PaymentService). The secret key comes from PAYSTACK_SECRET_KEY / AWS SSM only.
 */
class PaystackProvider implements PaymentProvider
{
    public function key(): string
    {
        return 'paystack';
    }

    public function chargeCurrencies(): array
    {
        return config('payments.providers.paystack.currencies', ['ZAR']);
    }

    public function createCheckout(Payment $payment, string $returnUrl, string $cancelUrl, string $buyerEmail): CheckoutSession
    {
        // Our own reference, so the payment can be matched before Paystack has answered.
        $reference = "boschifai-{$payment->id}-".Str::lower(Str::random(10));

        $response = $this->api()->post('/transaction/initialize', [
            'email' => $buyerEmail,
            'amount' => $payment->charge_amount_minor, // in the currency's subunit (cents)
            'currency' => $payment->charge_currency,
            'reference' => $reference,
            'callback_url' => $returnUrl,
            'metadata' => ['payment_id' => $payment->id, 'cancel_action' => $cancelUrl],
        ]);

        $url = $response->json('data.authorization_url');
        if (! $response->successful() || ! $response->json('status') || ! is_string($url)) {
            throw new RuntimeException("Paystack didn't start the checkout (HTTP {$response->status()}).");
        }

        return new CheckoutSession($url, $reference);
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) config('payments.providers.paystack.secret_key');
        $signature = (string) $request->header('x-paystack-signature');

        return $secret !== '' && $signature !== ''
            && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $event = (string) $request->json('event');
        $data = (array) $request->json('data');

        return new WebhookEvent(
            // Paystack events have no id of their own: the event type plus the transaction id is
            // unique for what matters here (one charge.success per transaction).
            eventId: $event.':'.($data['id'] ?? $data['reference'] ?? Str::uuid()),
            type: $event,
            providerRef: isset($data['reference']) ? (string) $data['reference'] : null,
            outcome: $event === 'charge.success' ? WebhookEvent::PAID : null,
            // No customer, card (authorization) or IP details are kept.
            redactedPayload: array_filter([
                'event' => $event,
                'id' => $data['id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'status' => $data['status'] ?? null,
                'amount' => $data['amount'] ?? null,
                'currency' => $data['currency'] ?? null,
                'channel' => $data['channel'] ?? null,
                'paid_at' => $data['paid_at'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    public function confirm(Payment $payment): ConfirmedPayment
    {
        $response = $this->api()->get('/transaction/verify/'.rawurlencode((string) $payment->provider_ref));
        if (! $response->successful()) {
            // Retried by Paystack's own webhook retries (PaymentService rolls back on an exception).
            throw new RuntimeException("Paystack couldn't confirm the payment (HTTP {$response->status()}).");
        }

        return new ConfirmedPayment(
            paid: $response->json('data.status') === 'success' && $response->json('data.reference') === $payment->provider_ref,
            amountMinor: (int) $response->json('data.amount'),
            currency: (string) $response->json('data.currency'),
        );
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl((string) config('payments.providers.paystack.base_url'))
            ->withToken((string) config('payments.providers.paystack.secret_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }
}
