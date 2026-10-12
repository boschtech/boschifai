<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
