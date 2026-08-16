<?php

namespace App\Http\Controllers;

use App\Services\Github\GithubOAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A real full-browser-navigation redirect (not an XHR that hands a URL back to JS for
 * `window.location.href` to follow) — lives in routes/web.php, not api.php, for the same reason
 * GithubOAuthCallbackController does. This used to be a JSON endpoint the frontend fetched and
 * then redirected to itself; that broke the click's "user gesture" status across the `await`,
 * which browsers can use to silently block the resulting `window.location.href` — the real cause
 * of "I have to click Connect GitHub a couple of times." A plain `<a href>` straight to this
 * route is a normal top-level navigation a browser never blocks.
 */
class GithubOAuthRedirectController extends Controller
{
    public function __invoke(GithubOAuthService $githubAuth)
    {
        abort_unless(
            config('boschifai.github.oauth_client_id'),
            422,
            'GitHub OAuth App is not configured — see config/boschifai.php (github.oauth_client_id).'
        );

        $state = Str::random(40);
        Cache::put("github:oauth_state:{$state}", true, now()->addMinutes(10));

        return redirect()->away($githubAuth->authorizeUrl($state));
    }
}
