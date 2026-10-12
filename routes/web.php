<?php

use App\Http\Controllers\GithubOAuthCallbackController;
use App\Http\Controllers\GithubOAuthRedirectController;
use Illuminate\Support\Facades\Route;

// Must be registered above the SPA catch-all below — GitHub does a real full-browser
// navigation here, which the catch-all would otherwise swallow and render the SPA shell for.
Route::middleware('auth')->group(function () {
    Route::get('/github/authorize', GithubOAuthRedirectController::class)->name('github.authorize');
    Route::get('/github/callback', GithubOAuthCallbackController::class)->name('github.callback');
});

// Named so Fortify's password-reset email can link here; the SPA renders the reset form.
Route::get('/reset-password/{token}', fn () => view('app'))->name('password.reset');

Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
