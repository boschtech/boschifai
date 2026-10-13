<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * One payment provider (Stripe, Paystack, PayFast…). PaymentService does everything else —
 * quoting, recording, deduplicating, checking amounts and granting credits — so an adapter only
 * talks to its provider.
 */
interface PaymentProvider
{
    /** Short id used in config, URLs and the payments table, e.g. "stripe". */
    public function key(): string;

    /**
     * ISO 4217 currencies the provider can charge customers in, e.g. ["ZAR"]; the buyer picks one.
     *
     * @return list<string>
     */
    public function chargeCurrencies(): array;

    /**
     * Starts a hosted checkout for $payment (in $payment->charge_currency) and returns where to
     * send the customer. $buyerEmail is passed because some providers require one for receipts.
     */
    public function createCheckout(Payment $payment, string $returnUrl, string $cancelUrl, string $buyerEmail): CheckoutSession;

    /** Whether the webhook really came from the provider (signature over the raw body). */
    public function verifyWebhook(Request $request): bool;

    /** Reads a verified webhook. The payload it returns must already be redacted. */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * Asks the provider directly (server to server) what happened to $payment — the webhook's
     * own claims are never trusted for granting credits.
     */
    public function confirm(Payment $payment): ConfirmedPayment;
}
