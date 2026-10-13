<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\ConfirmedPayment;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\WebhookEvent;
use Closure;
use Illuminate\Http\Request;

/**
 * A stand-in payment provider: webhooks are signed with an HMAC of the raw body (like the real
 * providers), and what "the provider" answers when a payment is confirmed is up to the test.
 */
class FakePaymentProvider implements PaymentProvider
{
    public const SECRET = 'fake-webhook-secret';

    /** @var Closure(Payment): ConfirmedPayment */
    public Closure $confirmWith;

    public int $confirmations = 0;

    /** @param  list<string>  $currencies */
    public function __construct(private array $currencies = ['USD'])
    {
        $this->confirmWith = fn (Payment $p) => new ConfirmedPayment(true, $p->charge_amount_minor, $p->charge_currency);
    }

    public function key(): string
    {
        return 'fake';
    }

    public bool $checkoutFails = false;

    public function chargeCurrencies(): array
    {
        return $this->currencies;
    }

    public function createCheckout(Payment $payment, string $returnUrl, string $cancelUrl, string $buyerEmail): CheckoutSession
    {
        if ($this->checkoutFails) {
            throw new \RuntimeException('Provider API is down');
        }

        return new CheckoutSession("https://pay.example.test/checkout/{$payment->id}?return=".urlencode($returnUrl), "fake_ref_{$payment->id}");
    }

    public function verifyWebhook(Request $request): bool
    {
        return hash_equals(self::sign($request->getContent()), (string) $request->header('X-Fake-Signature'));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $body = json_decode($request->getContent(), true);

        return new WebhookEvent(
            eventId: $body['id'],
            type: $body['type'],
            providerRef: $body['ref'] ?? null,
            outcome: $body['outcome'] ?? null,
            // Like a real adapter: keep what's useful for support, drop card and buyer details.
            redactedPayload: ['type' => $body['type'], 'ref' => $body['ref'] ?? null],
        );
    }

    public function confirm(Payment $payment): ConfirmedPayment
    {
        $this->confirmations++;

        return ($this->confirmWith)($payment);
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::SECRET);
    }
}
