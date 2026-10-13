<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditLedger;
use App\Support\Billing\MicroUsd;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Buying credits, independent of the provider:
 *
 * - startCheckout() quotes a credit pack in the provider's currency, records a pending payment
 *   and returns the provider's hosted checkout to send the customer to.
 * - handleWebhook() is the ONLY way credits are granted. A webhook must carry a valid signature;
 *   each event is processed once; and a "paid" event is then confirmed with the provider directly
 *   — its amount and currency must match the stored quote exactly — before the credits are added
 *   (once per payment, see CreditLedger::purchase). Anything that doesn't match is parked for a
 *   person to review, never credited.
 *
 * Amounts assume two-decimal currencies (USD, ZAR).
 */
class PaymentService
{
    public function __construct(
        private PaymentProviderRegistry $providers,
        private CreditLedger $ledger,
        private ExchangeRates $rates,
    ) {}

    /**
     * @return array{credits_micro: int, price_usd_micro: int, charge_currency: string, charge_amount_minor: int, fx_rate: ?string, fx_source: ?string, fx_quoted_at: ?Carbon}
     *
     * @throws PaymentUnavailable
     */
    public function quote(string $packKey, PaymentProvider $provider, string $currency): array
    {
        $pack = config("billing.packs.{$packKey}");
        if (! is_array($pack)) {
            throw new PaymentUnavailable('That credit pack is not available.');
        }

        $credits = MicroUsd::parse($pack['credits_usd']);
        $price = MicroUsd::parse($pack['price_usd']);
        $currency = strtoupper($currency);
        if (! in_array($currency, $this->currenciesFor($provider), true)) {
            throw new PaymentUnavailable("Payments in {$currency} aren't available with this payment method.");
        }

        if ($currency === 'USD') {
            return [
                'credits_micro' => $credits, 'price_usd_micro' => $price, 'charge_currency' => 'USD',
                'charge_amount_minor' => $this->toCentsRoundingUp($price),
                'fx_rate' => null, 'fx_source' => null, 'fx_quoted_at' => null,
            ];
        }

        $fx = $this->rates->current($currency);
        if ($fx === null) {
            throw new PaymentUnavailable("Payments in {$currency} aren't available right now.");
        }
        $rate = $fx['rate_millionths'];
        $buffer = MicroUsd::parseOptional(config("payments.fx.{$currency}.buffer")) ?? 0;
        $local = MicroUsd::multiply(MicroUsd::multiply($price, $rate), MicroUsd::PER_USD + $buffer);

        return [
            'credits_micro' => $credits, 'price_usd_micro' => $price, 'charge_currency' => $currency,
            'charge_amount_minor' => $this->toCentsRoundingUp($local),
            'fx_rate' => $fx['rate'], 'fx_source' => $fx['source'], 'fx_quoted_at' => now(),
        ];
    }

    /**
     * The currencies a buyer can actually pay in with $provider: those it can charge, and that
     * BoschifAI can quote — USD always; anything else only with an exchange rate configured.
     *
     * @return list<string>
     */
    public function currenciesFor(PaymentProvider $provider): array
    {
        return array_values(array_filter(
            array_map('strtoupper', $provider->chargeCurrencies()),
            fn (string $currency) => $currency === 'USD' || $this->rates->current($currency) !== null,
        ));
    }

    /**
     * @return array{0: Payment, 1: CheckoutSession}
     *
     * @throws PaymentUnavailable
     */
    public function startCheckout(Workspace $workspace, User $buyer, string $packKey, string $providerKey, string $currency): array
    {
        if (! $this->providers->isEnabled($providerKey)) {
            throw new PaymentUnavailable('That payment method is not available.');
        }
        $provider = $this->providers->find($providerKey);

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'provider' => $providerKey,
            'pack' => $packKey,
            'status' => PaymentStatus::Pending,
            'created_by_user_id' => $buyer->id,
            ...$this->quote($packKey, $provider, $currency),
        ]);

        try {
            $session = $provider->createCheckout($payment, url("/billing/payments/{$payment->id}"), url('/billing?cancelled=1'), $buyer->email);
        } catch (\Throwable $e) {
            report($e);
            $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'The payment provider could not start the checkout.']);

            throw new PaymentUnavailable("Couldn't start the checkout with the payment provider. Nothing was charged — try again in a moment.");
        }
        $payment->update(['provider_ref' => $session->providerRef]);

        return [$payment, $session];
    }

    /**
     * @return string what happened, for logs and tests: invalid_signature, duplicate,
     *                unknown_payment, credited, already_credited, not_paid, needs_review, failed,
     *                ignored
     */
    public function handleWebhook(PaymentProvider $provider, Request $request): string
    {
        if (! $provider->verifyWebhook($request)) {
            // Someone is posting to the webhook without the provider's secret — worth an alert.
            report(new RuntimeException("Rejected a {$provider->key()} payment webhook with an invalid signature."));

            return 'invalid_signature';
        }

        $event = $provider->parseWebhook($request);

        // One transaction: if anything below fails, the event isn't marked as seen, so the
        // provider's retry processes it again.
        return DB::transaction(function () use ($provider, $event) {
            $isNew = PaymentWebhookEvent::insertOrIgnore([
                'provider' => $provider->key(), 'event_id' => $event->eventId, 'event_type' => $event->type,
                'payload' => json_encode($event->redactedPayload), 'created_at' => now(),
            ]) === 1;
            if (! $isNew) {
                return 'duplicate';
            }

            $payment = $event->providerRef === null ? null : Payment::where('provider', $provider->key())
                ->where('provider_ref', $event->providerRef)
                ->lockForUpdate()
                ->first();

            $outcome = match (true) {
                $event->providerRef === null => 'ignored',
                $payment === null => 'unknown_payment',
                $event->outcome === WebhookEvent::PAID => $this->settlePaid($provider, $payment),
                $event->outcome === WebhookEvent::FAILED && $payment->status === PaymentStatus::Pending => $this->markFailed($payment, $event->type),
                default => 'ignored',
            };

            PaymentWebhookEvent::where('provider', $provider->key())->where('event_id', $event->eventId)
                ->update(['payment_id' => $payment?->id, 'outcome' => $outcome, 'processed_at' => now()]);

            return $outcome;
        });
    }

    private function settlePaid(PaymentProvider $provider, Payment $payment): string
    {
        if ($payment->status === PaymentStatus::Succeeded) {
            return 'already_credited';
        }

        $confirmed = $provider->confirm($payment);
        if (! $confirmed->paid) {
            return 'not_paid';
        }

        if ($confirmed->amountMinor !== $payment->charge_amount_minor || strtoupper($confirmed->currency) !== $payment->charge_currency) {
            $reason = sprintf('Provider confirmed %d %s; expected %d %s.', $confirmed->amountMinor, strtoupper($confirmed->currency), $payment->charge_amount_minor, $payment->charge_currency);
            $payment->update(['status' => PaymentStatus::NeedsReview, 'failure_reason' => $reason]);
            report(new RuntimeException("Payment {$payment->id} needs review: {$reason}"));

            return 'needs_review';
        }

        $entry = $this->ledger->purchase($payment);
        $payment->update(['status' => PaymentStatus::Succeeded, 'paid_at' => now(), 'ledger_entry_id' => $entry->id, 'failure_reason' => null]);
        AuditEvent::record('credits.purchased', $payment->workspace, null, $payment, [
            'credits_usd' => MicroUsd::format($payment->credits_micro, 2),
            'charged' => MicroUsd::format($payment->charge_amount_minor * 10_000, 2).' '.$payment->charge_currency,
            'provider' => $payment->provider,
        ]);

        return 'credited';
    }

    private function markFailed(Payment $payment, string $eventType): string
    {
        $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => "The payment provider reported: {$eventType}"]);

        return 'failed';
    }

    private function toCentsRoundingUp(int $micro): int
    {
        return intdiv($micro + 9_999, 10_000);
    }
}
