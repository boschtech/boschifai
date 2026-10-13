<?php

namespace App\Providers;

use App\Services\ClaudeRunner\Credentials\ClaudeCredentialResolver;
use App\Services\ClaudeRunner\Credentials\WorkspaceCredentialResolver;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\Providers\PaystackProvider;
use App\Support\CurrentWorkspace;
use App\Support\Security\CredentialsEncrypter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request (and per queued job), never shared between them.
        $this->app->scoped(CurrentWorkspace::class);

        // Which AI credentials each pipeline step runs with: the workspace's own key if it saved
        // one, otherwise the platform's.
        $this->app->bind(ClaudeCredentialResolver::class, WorkspaceCredentialResolver::class);

        // Payment provider adapters; one is offered only when configured AND in PAYMENTS_ENABLED.
        $this->app->singleton(PaymentProviderRegistry::class, function () {
            $registry = new PaymentProviderRegistry;
            if (config('payments.providers.paystack.secret_key')) {
                $registry->register(new PaystackProvider);
            }

            return $registry;
        });
        $this->app->singleton(CredentialsEncrypter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Applies to password reset and change (Fortify's PasswordValidationRules) and to
        // `boschifai:create-user`.
        Password::defaults(fn () => Password::min(12)->letters()->numbers());
    }
}
