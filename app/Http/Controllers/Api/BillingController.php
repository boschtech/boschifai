<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\WorkspaceAiCredential;
use App\Services\Billing\CreditLedger;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentUnavailable;
use App\Support\Billing\MicroUsd;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;

/** The workspace's credits and buying more (owners and admins). */
class BillingController extends Controller
{
    public function show(CurrentWorkspace $current, CreditLedger $ledger, PaymentProviderRegistry $providers, PaymentService $payments)
    {
        $balance = $ledger->balance($current->get());

        return response()->json(['data' => [
            'available_usd' => MicroUsd::format($balance->available_micro, 2),
            'held_usd' => MicroUsd::format($balance->held_micro, 2),
            // Runs on the workspace's own Anthropic key don't use credits.
            'uses_own_ai_key' => WorkspaceAiCredential::activeFor($current->id()) !== null,
            'packs' => collect(config('billing.packs'))->map(function ($pack, $key) {
                $credits = MicroUsd::parse($pack['credits_usd']);
                $price = MicroUsd::parse($pack['price_usd']);

                return [
                    'key' => $key,
                    'credits_usd' => MicroUsd::format($credits, 2),
                    'price_usd' => MicroUsd::format($price, 2),
                    // Credits beyond the price paid, e.g. "5.00" on a US$50 pack of 55 credits.
                    'bonus_usd' => $credits > $price ? MicroUsd::format($credits - $price, 2) : null,
                ];
            })->values(),
            // Only providers a buyer can actually pay with — at least one usable currency.
            'providers' => collect($providers->enabled())
                ->map(fn ($p) => ['key' => $p->key(), 'currencies' => $payments->currenciesFor($p)])
                ->filter(fn ($p) => $p['currencies'] !== [])
                ->values(),
            'payments' => Payment::forWorkspace($current->id())->latest('id')->limit(20)->get()->map(fn (Payment $p) => $this->paymentData($p)),
        ]]);
    }

    public function checkout(Request $request, CurrentWorkspace $current, PaymentService $payments)
    {
        $validated = $request->validate([
            'pack' => ['required', 'string', 'max:50'],
            'provider' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        try {
            [$payment, $session] = $payments->startCheckout($current->get(), $request->user(), $validated['pack'], $validated['provider'], $validated['currency']);
        } catch (PaymentUnavailable $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => [...$this->paymentData($payment), 'redirect_url' => $session->redirectUrl]], 201);
    }

    /** For the page the provider sends the customer back to: polls until the webhook lands. */
    public function payment(Payment $payment)
    {
        return response()->json(['data' => $this->paymentData($payment)]);
    }

    private function paymentData(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'status' => $payment->status->value,
            'pack' => $payment->pack,
            'provider' => $payment->provider,
            'credits_usd' => MicroUsd::format($payment->credits_micro, 2),
            'charge' => MicroUsd::format($payment->charge_amount_minor * 10_000, 2),
            'charge_currency' => $payment->charge_currency,
            'created_at' => $payment->created_at,
            'paid_at' => $payment->paid_at,
        ];
    }
}
