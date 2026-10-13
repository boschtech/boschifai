<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

/**
 * Receives payment provider webhooks (routes/webhooks.php: no session, no CSRF, no sign-in —
 * the provider's signature is the authentication). Anything unverified gets a 400; everything
 * else a 200 so the provider stops retrying, whatever PaymentService decided.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentProviderRegistry $providers, PaymentService $payments)
    {
        abort_unless($providers->isEnabled($provider), 404);

        $outcome = $payments->handleWebhook($providers->find($provider), $request);

        return $outcome === 'invalid_signature'
            ? response()->json(['message' => 'Invalid signature.'], 400)
            : response()->json(['received' => true]);
    }
}
