<?php

use App\Http\Middleware\RequireWorkspace;
use App\Http\Middleware\RequireWorkspaceManager;
use App\Services\Billing\InsufficientCredits;
use App\Support\Billing\MicroUsd;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::prefix('webhooks')->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The Vue SPA is served from this same app, so the API authenticates with Sanctum's
        // cookie-based session (CSRF-protected) rather than API tokens.
        $middleware->statefulApi();
        // Full-page navigations by a guest (e.g. /github/authorize) land on the SPA's login page.
        $middleware->redirectGuestsTo('/login');
        $middleware->alias([
            'workspace' => RequireWorkspace::class,
            'workspace.manage' => RequireWorkspaceManager::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A step the workspace can't afford (billing.enforce): 402 with the figures, so the app
        // can say how much is needed rather than show a generic error.
        $exceptions->render(function (InsufficientCredits $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage().' Ask a workspace owner or admin to add credits.',
                    'code' => 'insufficient_credits',
                    'available_usd' => MicroUsd::format($e->availableMicro, 2),
                    'required_usd' => MicroUsd::format($e->requiredMicro, 2),
                ], 402);
            }
        });
    })->create();
