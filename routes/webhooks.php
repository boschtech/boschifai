<?php

use App\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Server-to-server callbacks. Registered outside the web and api groups (bootstrap/app.php):
| no session, no CSRF and no sign-in — each controller authenticates the sender itself.
*/
Route::post('/payments/{provider}', PaymentWebhookController::class)
    ->where('provider', '[a-z]+')
    ->name('webhooks.payments');
