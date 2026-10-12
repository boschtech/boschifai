<?php

namespace App\Providers;

use App\Services\ClaudeRunner\Credentials\ClaudeCredentialResolver;
use App\Services\ClaudeRunner\Credentials\PlatformCredentialResolver;
use App\Support\CurrentWorkspace;
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

        // Which AI credentials each pipeline step runs with (Phase 3 swaps in per-workspace ones).
        $this->app->bind(ClaudeCredentialResolver::class, PlatformCredentialResolver::class);
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
