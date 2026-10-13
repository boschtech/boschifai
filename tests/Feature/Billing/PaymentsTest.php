<?php

namespace Tests\Feature\Billing;

use App\Enums\CreditEntryType;
use App\Enums\PaymentStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditLedger;
use App\Services\Payments\ConfirmedPayment;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentUnavailable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SignsInUser;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

/**
 * Buying credits end to end against a fake provider: checkout, then credits granted only by a
 * signed webhook, confirmed with the provider, for exactly the quoted amount, exactly once.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private FakePaymentProvider $provider;

    /** @var list<string> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.packs' => ['starter' => ['credits_usd' => '10', 'price_usd' => '10'], 'team' => ['credits_usd' => '50', 'price_usd' => '45.50']],
            'payments.enabled' => ['fake'],
        ]);
        $this->provider = new FakePaymentProvider;
        app(PaymentProviderRegistry::class)->register($this->provider);

        app(ExceptionHandler::class)->reportable(function (RuntimeException $e) {
            $this->reported[] = $e->getMessage();

            return false;
        });
    }

    private function checkout(string $pack = 'team'): Payment
    {
        $id = $this->postJson('/api/billing/checkout', ['pack' => $pack, 'provider' => 'fake', 'currency' => 'USD'])->assertCreated()->json('data.id');

        return Payment::findOrFail($id);
    }

    private function webhook(array $event, ?string $signature = null)
    {
        $body = json_encode($event);

        return $this->call('POST', '/webhooks/payments/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => $signature ?? FakePaymentProvider::sign($body),
        ], $body);
    }

    private function paid(Payment $payment, string $eventId = 'evt_1')
    {
        return $this->webhook(['id' => $eventId, 'type' => 'payment.succeeded', 'ref' => $payment->provider_ref, 'outcome' => 'paid', 'card' => '4242424242424242']);
    }

    private function available(): int
    {
        return app(CreditLedger::class)->balance($this->workspace())->available_micro;
    }

    // ── Quotes ─────────────────────────────────────────────────────────────────────────

    public function test_a_usd_provider_is_charged_the_pack_price(): void
    {
        $quote = app(PaymentService::class)->quote('team', $this->provider, 'usd');

        $this->assertSame(['credits_micro' => 50_000_000, 'price_usd_micro' => 45_500_000, 'charge_currency' => 'USD', 'charge_amount_minor' => 4550], array_slice($quote, 0, 4));
        $this->assertNull($quote['fx_rate']);
    }

    public function test_another_currency_is_quoted_at_the_admin_rate_plus_buffer_rounded_up(): void
    {
        config(['payments.fx.ZAR' => ['rate' => '18.2531', 'buffer' => '0.03', 'source' => 'admin']]);

        $quote = app(PaymentService::class)->quote('team', new FakePaymentProvider(['ZAR']), 'ZAR');

        // 45.50 × 18.2531 = 830.516050; × 1.03 = 855.431531… → R855.44
        $this->assertSame(['ZAR', 85544, '18.2531', 'admin'], [$quote['charge_currency'], $quote['charge_amount_minor'], $quote['fx_rate'], $quote['fx_source']]);
        $this->assertNotNull($quote['fx_quoted_at']);
    }

    public function test_a_currency_without_a_rate_or_an_unknown_pack_cannot_be_quoted(): void
    {
        config(['payments.fx.ZAR.rate' => null]);

        foreach ([
            ['team', new FakePaymentProvider(['ZAR']), 'ZAR', "Payments in ZAR aren't available with this payment method."],
            ['team', $this->provider, 'EUR', "Payments in EUR aren't available with this payment method."],
            ['huge', $this->provider, 'USD', 'That credit pack is not available.'],
        ] as [$pack, $provider, $currency, $message]) {
            try {
                app(PaymentService::class)->quote($pack, $provider, $currency);
                $this->fail('Expected the quote to be refused.');
            } catch (PaymentUnavailable $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    // ── Checkout ───────────────────────────────────────────────────────────────────────

    public function test_an_admin_starts_a_checkout_and_is_sent_to_the_provider(): void
    {
        $response = $this->postJson('/api/billing/checkout', ['pack' => 'team', 'provider' => 'fake', 'currency' => 'USD'])->assertCreated();

        $payment = Payment::sole();
        $this->assertSame([PaymentStatus::Pending, "fake_ref_{$payment->id}", 4550, 'USD', 50_000_000], [$payment->status, $payment->provider_ref, $payment->charge_amount_minor, $payment->charge_currency, $payment->credits_micro]);
        $this->assertSame($this->workspace()->id, $payment->workspace_id);
        $this->assertStringStartsWith("https://pay.example.test/checkout/{$payment->id}?return=", $response->json('data.redirect_url'));
        $this->assertStringContainsString(urlencode(url("/billing/payments/{$payment->id}")), $response->json('data.redirect_url'));
        $response->assertJsonPath('data.status', 'pending')->assertJsonPath('data.charge', '45.50');
        $this->assertSame(0, $this->available(), 'Starting a checkout grants nothing.');
    }

    public function test_checkout_is_refused_for_a_disabled_provider_or_unknown_pack(): void
    {
        $this->postJson('/api/billing/checkout', ['pack' => 'huge', 'provider' => 'fake', 'currency' => 'USD'])->assertStatus(422)->assertJsonPath('message', 'That credit pack is not available.');

        config(['payments.enabled' => []]);
        $this->postJson('/api/billing/checkout', ['pack' => 'team', 'provider' => 'fake', 'currency' => 'USD'])->assertStatus(422)->assertJsonPath('message', 'That payment method is not available.');
        $this->postJson('/api/billing/checkout', ['pack' => 'team', 'provider' => 'stripe', 'currency' => 'USD'])->assertStatus(422);

        $this->postJson('/api/billing/checkout', [])->assertJsonValidationErrors(['pack', 'provider', 'currency']);
        $this->assertSame(0, Payment::count());
    }

    public function test_the_buyer_chooses_among_the_currencies_the_provider_can_charge(): void
    {
        $zar = new class(['ZAR', 'USD']) extends FakePaymentProvider
        {
            public function key(): string
            {
                return 'multi';
            }
        };
        app(PaymentProviderRegistry::class)->register($zar);
        config(['payments.enabled' => ['fake', 'multi'], 'payments.fx.ZAR' => ['rate' => '18', 'buffer' => '0', 'source' => 'admin']]);

        $this->getJson('/api/billing')->assertJsonPath('data.providers', [
            ['key' => 'fake', 'currencies' => ['USD']],
            ['key' => 'multi', 'currencies' => ['ZAR', 'USD']],
        ]);

        $this->postJson('/api/billing/checkout', ['pack' => 'starter', 'provider' => 'multi', 'currency' => 'zar'])->assertCreated()
            ->assertJsonPath('data.charge', '180.00')
            ->assertJsonPath('data.charge_currency', 'ZAR');
        $this->postJson('/api/billing/checkout', ['pack' => 'starter', 'provider' => 'fake', 'currency' => 'ZAR'])->assertStatus(422);

        // Without an exchange rate, ZAR isn't offered — and a provider with nothing left isn't either.
        config(['payments.fx.ZAR.rate' => null]);
        $this->getJson('/api/billing')->assertJsonPath('data.providers', [['key' => 'fake', 'currencies' => ['USD']], ['key' => 'multi', 'currencies' => ['USD']]]);
    }

    public function test_a_checkout_the_provider_cannot_start_is_marked_failed_and_charges_nothing(): void
    {
        $this->provider->checkoutFails = true;

        $this->postJson('/api/billing/checkout', ['pack' => 'team', 'provider' => 'fake', 'currency' => 'USD'])
            ->assertStatus(422)
            ->assertJsonPath('message', "Couldn't start the checkout with the payment provider. Nothing was charged — try again in a moment.");

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertNull($payment->provider_ref);
        $this->assertSame(['Provider API is down'], $this->reported);
    }

    public function test_members_cannot_see_billing_or_buy_credits(): void
    {
        $member = User::factory()->create();
        $this->workspace()->addMember($member, WorkspaceRole::Member);
        $member->forceFill(['current_workspace_id' => $this->workspace()->id])->save();
        $this->actingAs($member);

        $this->getJson('/api/billing')->assertForbidden();
        $this->postJson('/api/billing/checkout', ['pack' => 'team', 'provider' => 'fake', 'currency' => 'USD'])->assertForbidden();
    }

    // ── Webhooks ───────────────────────────────────────────────────────────────────────

    public function test_a_confirmed_payment_grants_its_credits_once_and_is_audited(): void
    {
        $payment = $this->checkout();

        $this->paid($payment)->assertOk()->assertExactJson(['received' => true]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(50_000_000, $this->available());
        $entry = CreditLedgerEntry::where('type', CreditEntryType::Purchase)->sole();
        $this->assertSame([$entry->id, ['payment_id' => $payment->id, 'provider' => 'fake']], [$payment->ledger_entry_id, $entry->meta]);
        $this->assertSame(['credits_usd' => '50.00', 'charged' => '45.50 USD', 'provider' => 'fake'], AuditEvent::where('action', 'credits.purchased')->sole()->meta);

        $event = PaymentWebhookEvent::sole();
        $this->assertSame(['credited', $payment->id], [$event->outcome, $event->payment_id]);
        $this->assertSame(['type' => 'payment.succeeded', 'ref' => $payment->provider_ref], $event->payload, 'Stored redacted — no card number.');
        $this->assertTrue(app(CreditLedger::class)->isConsistent($this->workspace()));
    }

    public function test_a_redelivered_event_or_a_second_paid_event_never_credits_twice(): void
    {
        $payment = $this->checkout();
        $this->paid($payment, 'evt_1');

        $this->paid($payment, 'evt_1')->assertOk();
        $this->paid($payment, 'evt_2')->assertOk();

        $this->assertSame(50_000_000, $this->available());
        $this->assertSame(1, $this->provider->confirmations, 'A duplicate is dropped before asking the provider; an already-paid payment is never re-confirmed.');
        $this->assertSame(['credited', 'already_credited'], PaymentWebhookEvent::orderBy('id')->pluck('outcome')->all());
    }

    public function test_an_unsigned_or_forged_webhook_is_rejected_and_reported(): void
    {
        $payment = $this->checkout();

        $this->webhook(['id' => 'evt_x', 'type' => 'payment.succeeded', 'ref' => $payment->provider_ref, 'outcome' => 'paid'], 'forged')
            ->assertStatus(400);

        $this->assertSame(0, $this->available());
        $this->assertSame(0, PaymentWebhookEvent::count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(['Rejected a fake payment webhook with an invalid signature.'], $this->reported);
    }

    public function test_the_webhook_is_never_trusted_without_confirming_with_the_provider(): void
    {
        $payment = $this->checkout();
        $this->provider->confirmWith = fn () => new ConfirmedPayment(false, 0, 'USD');

        $this->paid($payment)->assertOk();

        $this->assertSame(0, $this->available());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame('not_paid', PaymentWebhookEvent::sole()->outcome);
    }

    public function test_a_payment_for_the_wrong_amount_or_currency_is_held_for_review_not_credited(): void
    {
        foreach ([[100, 'USD'], [4550, 'ZAR']] as $i => [$amount, $currency]) {
            $payment = $this->checkout();
            $this->provider->confirmWith = fn () => new ConfirmedPayment(true, $amount, $currency);

            $this->paid($payment, "evt_{$i}")->assertOk();

            $payment->refresh();
            $this->assertSame(PaymentStatus::NeedsReview, $payment->status);
            $this->assertSame("Provider confirmed {$amount} {$currency}; expected 4550 USD.", $payment->failure_reason);
        }

        $this->assertSame(0, $this->available());
        $this->assertCount(2, $this->reported);
    }

    public function test_a_failed_payment_is_marked_failed(): void
    {
        $payment = $this->checkout();

        $this->webhook(['id' => 'evt_f', 'type' => 'payment.failed', 'ref' => $payment->provider_ref, 'outcome' => 'failed'])->assertOk();

        $payment->refresh();
        $this->assertSame([PaymentStatus::Failed, 'The payment provider reported: payment.failed'], [$payment->status, $payment->failure_reason]);

        // A late "failed" never undoes a payment that already succeeded.
        $other = $this->checkout('starter');
        $this->paid($other, 'evt_ok');
        $this->webhook(['id' => 'evt_f2', 'type' => 'payment.failed', 'ref' => $other->provider_ref, 'outcome' => 'failed'])->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $other->fresh()->status);
        $this->assertSame('ignored', PaymentWebhookEvent::where('event_id', 'evt_f2')->value('outcome'));
    }

    public function test_events_for_unknown_payments_or_without_an_outcome_are_recorded_and_ignored(): void
    {
        $this->webhook(['id' => 'evt_u', 'type' => 'payment.succeeded', 'ref' => 'not-ours', 'outcome' => 'paid'])->assertOk();
        $this->webhook(['id' => 'evt_n', 'type' => 'customer.updated'])->assertOk();

        $this->assertSame(['unknown_payment', 'ignored'], PaymentWebhookEvent::orderBy('id')->pluck('outcome')->all());
        $this->assertSame(0, $this->available());
    }

    public function test_if_confirming_fails_the_event_is_not_marked_seen_so_the_retry_credits(): void
    {
        $payment = $this->checkout();
        $this->provider->confirmWith = fn () => throw new RuntimeException('Provider API timed out');
        $this->withoutExceptionHandling();

        try {
            $this->paid($payment);
            $this->fail('Expected the provider error to surface (the provider then retries).');
        } catch (RuntimeException $e) {
            $this->assertSame('Provider API timed out', $e->getMessage());
        }
        $this->assertSame(0, PaymentWebhookEvent::count());

        $this->provider->confirmWith = fn (Payment $p) => new ConfirmedPayment(true, $p->charge_amount_minor, $p->charge_currency);
        $this->paid($payment)->assertOk();
        $this->assertSame(50_000_000, $this->available());
    }

    public function test_a_provider_that_is_not_enabled_has_no_webhook(): void
    {
        config(['payments.enabled' => []]);

        $this->webhook(['id' => 'evt_1', 'type' => 'x'])->assertNotFound();
        $this->call('POST', '/webhooks/payments/stripe')->assertNotFound();
    }

    // ── Reading ────────────────────────────────────────────────────────────────────────

    public function test_billing_shows_the_balance_packs_providers_and_recent_payments(): void
    {
        $payment = $this->checkout('starter');
        $this->paid($payment);

        $this->getJson('/api/billing')
            ->assertOk()
            ->assertJsonPath('data.available_usd', '10.00')
            ->assertJsonPath('data.held_usd', '0.00')
            ->assertJsonPath('data.packs', [
                ['key' => 'starter', 'credits_usd' => '10.00', 'price_usd' => '10.00'],
                ['key' => 'team', 'credits_usd' => '50.00', 'price_usd' => '45.50'],
            ])
            ->assertJsonPath('data.providers', [['key' => 'fake', 'currencies' => ['USD']]])
            ->assertJsonPath('data.payments.0.status', 'succeeded');

        $this->getJson("/api/billing/payments/{$payment->id}")->assertOk()->assertJsonPath('data.status', 'succeeded');
    }

    public function test_another_workspaces_payment_is_not_found(): void
    {
        $other = Workspace::create(['name' => 'Other']);
        $theirs = Payment::create(['workspace_id' => $other->id, 'provider' => 'fake', 'pack' => 'team', 'credits_micro' => 1, 'price_usd_micro' => 1, 'charge_currency' => 'USD', 'charge_amount_minor' => 1, 'status' => PaymentStatus::Pending]);

        $this->getJson("/api/billing/payments/{$theirs->id}")->assertNotFound();
        $this->getJson('/api/billing')->assertJsonCount(0, 'data.payments');
    }
}
