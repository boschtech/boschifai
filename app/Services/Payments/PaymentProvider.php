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

    /** ISO 4217 currency the provider charges in, e.g. "USD" or "ZAR". */
    public function chargeCurrency(): string;

    /** Starts a hosted checkout for $payment and returns where to send the customer. */
    public function createCheckout(Payment $payment, string $returnUrl, string $cancelUrl): CheckoutSession;

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
