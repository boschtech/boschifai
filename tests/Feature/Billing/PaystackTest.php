<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\Billing\CreditLedger;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\Providers\PaystackProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/**
 * Buying credits through Paystack end to end, against a faked Paystack API: packs priced in USD,
 * paid in ZAR at the quoted rate, credited only after a signed charge.success is verified.
 */
class PaystackTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private const SECRET = 'sk_test_not_a_real_paystack_secret_key';

    /** What the fake Paystack's verify endpoint reports. */
    private array $verified = ['status' => 'success', 'amount' => 18000, 'currency' => 'ZAR'];

    /** HTTP statuses the fake Paystack answers with (one fake per test — a second would only add to it). */
    private int $initializeStatus = 200;

    private int $verifyStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.packs' => ['starter' => ['credits_usd' => '10', 'price_usd' => '10']],
            'payments.enabled' => ['paystack'],
            'payments.providers.paystack' => ['secret_key' => self::SECRET, 'currencies' => ['ZAR'], 'base_url' => 'https://api.paystack.co'],
            'payments.fx.ZAR' => ['rate' => '18', 'buffer' => '0', 'source' => 'admin'],
        ]);
        // The registry decides at resolution time; resolve it fresh with this config.
        app()->forgetInstance(PaymentProviderRegistry::class);

        Http::fake([
            'api.paystack.co/transaction/initialize' => fn (Request $request) => $this->initializeStatus !== 200
                ? Http::response(['status' => false, 'message' => 'Invalid key'], $this->initializeStatus)
                : Http::response(['status' => true, 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123', 'reference' => $request['reference'],
                ]]),
            'api.paystack.co/transaction/verify/*' => fn (Request $request) => $this->verifyStatus !== 200
                ? Http::response([], $this->verifyStatus)
                : Http::response(['status' => true, 'data' => [
                    'reference' => urldecode(basename(parse_url($request->url(), PHP_URL_PATH))), ...$this->verified,
                ]]),
        ]);
    }

    private function checkout(): Payment
    {
        $this->postJson('/api/billing/checkout', ['pack' => 'starter', 'provider' => 'paystack', 'currency' => 'ZAR'])
            ->assertCreated()
            ->assertJsonPath('data.redirect_url', 'https://checkout.paystack.com/abc123')
            ->assertJsonPath('data.charge', '180.00')
            ->assertJsonPath('data.charge_currency', 'ZAR');

        return Payment::sole();
    }

    private function webhook(array $event, ?string $signature = null)
    {
        $body = json_encode($event);

        return $this->call('POST', '/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    private function chargeSuccess(Payment $payment): array
    {
        return ['event' => 'charge.success', 'data' => [
            'id' => 302961, 'reference' => $payment->provider_ref, 'status' => 'success', 'amount' => 18000, 'currency' => 'ZAR',
            'channel' => 'card', 'customer' => ['email' => 'buyer@example.com'], 'authorization' => ['last4' => '4081', 'bin' => '408408'],
        ]];
    }

    public function test_paystack_is_offered_in_zar_only_once_configured(): void
    {
        $this->getJson('/api/billing')->assertJsonPath('data.providers', [['key' => 'paystack', 'currencies' => ['ZAR']]]);

        config(['payments.providers.paystack.secret_key' => null]);
        app()->forgetInstance(PaymentProviderRegistry::class);
        $this->getJson('/api/billing')->assertJsonPath('data.providers', []);
    }

    public function test_checkout_starts_a_paystack_transaction_for_the_quoted_rand_amount(): void
    {
        $payment = $this->checkout();

        $this->assertMatchesRegularExpression("/^boschifai-{$payment->id}-[a-z0-9]{10}$/", $payment->provider_ref);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request->header('Authorization') === ['Bearer '.self::SECRET]
            && $request['amount'] === 18000
            && $request['currency'] === 'ZAR'
            && $request['email'] === $this->user->email
            && $request['reference'] === $payment->provider_ref
            && $request['callback_url'] === url("/billing/payments/{$payment->id}")
            && $request['metadata'] === ['payment_id' => $payment->id, 'cancel_action' => url('/billing?cancelled=1')]);
    }

    public function test_a_verified_charge_success_grants_the_credits_and_stores_no_card_or_customer_details(): void
    {
        $payment = $this->checkout();

        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(10_000_000, app(CreditLedger::class)->balance($this->workspace())->available_micro);
        Http::assertSent(fn (Request $request) => $request->url() === "https://api.paystack.co/transaction/verify/{$payment->provider_ref}");
        $event = PaymentWebhookEvent::sole();
        $this->assertSame('charge.success:302961', $event->event_id);
        $this->assertSame(['event' => 'charge.success', 'id' => 302961, 'reference' => $payment->provider_ref, 'status' => 'success', 'amount' => 18000, 'currency' => 'ZAR', 'channel' => 'card'], $event->payload);
        $this->assertStringNotContainsString('buyer@example.com', json_encode($event->payload));
        $this->assertStringNotContainsString('4081', json_encode($event->payload));
    }

    public function test_a_redelivered_charge_success_credits_once(): void
    {
        $payment = $this->checkout();

        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->webhook($this->chargeSuccess($payment))->assertOk();

        $this->assertSame(10_000_000, app(CreditLedger::class)->balance($this->workspace())->available_micro);
        $this->assertSame(1, PaymentWebhookEvent::count());
    }

    public function test_an_unsigned_or_wrongly_signed_webhook_is_rejected(): void
    {
        $payment = $this->checkout();

        $this->webhook($this->chargeSuccess($payment), 'forged')->assertStatus(400);
        $this->webhook($this->chargeSuccess($payment), hash_hmac('sha512', 'something else', self::SECRET))->assertStatus(400);

        $this->assertSame(0, app(CreditLedger::class)->balance($this->workspace())->available_micro);
    }

    public function test_paystack_saying_it_is_not_paid_or_paid_a_different_amount_grants_nothing(): void
    {
        $payment = $this->checkout();
        $this->verified = ['status' => 'abandoned', 'amount' => 18000, 'currency' => 'ZAR'];
        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        $this->verified = ['status' => 'success', 'amount' => 100, 'currency' => 'ZAR'];
        $this->webhook(['event' => 'charge.success', 'data' => ['id' => 302962] + $this->chargeSuccess($payment)['data']])->assertOk();
        $this->assertSame(PaymentStatus::NeedsReview, $payment->fresh()->status);

        $this->assertSame(0, app(CreditLedger::class)->balance($this->workspace())->available_micro);
    }

    public function test_other_paystack_events_are_recorded_and_ignored(): void
    {
        $payment = $this->checkout();

        $this->webhook(['event' => 'transfer.success', 'data' => ['id' => 9, 'reference' => $payment->provider_ref]])->assertOk();

        $this->assertSame('ignored', PaymentWebhookEvent::sole()->outcome);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_paystack_failing_to_start_or_confirm_is_handled(): void
    {
        $this->initializeStatus = 401;
        $this->postJson('/api/billing/checkout', ['pack' => 'starter', 'provider' => 'paystack', 'currency' => 'ZAR'])->assertStatus(422);
        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);

        $provider = new PaystackProvider;
        $payment = Payment::sole();
        $payment->provider_ref = 'boschifai-x';
        $this->verifyStatus = 500;
        $this->expectExceptionMessage("Paystack couldn't confirm the payment (HTTP 500).");
        $provider->confirm($payment);
    }
}
