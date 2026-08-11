<?php

namespace App\Http\Controllers;

use App\Models\GithubConnection;
use App\Services\Github\GithubOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GitHub does a real full-browser-navigation redirect here (not an XHR), so this lives in
 * routes/web.php, not api.php — registered above the SPA catch-all so it isn't swallowed by it.
 */
class GithubOAuthCallbackController extends Controller
{
    public function __invoke(Request $request, GithubOAuthService $githubAuth)
    {
        $state = $request->query('state');

        abort_unless(
            $state && Cache::pull("github:oauth_state:{$state}"),
            419,
            'This GitHub connection link has expired or was already used — click "Connect GitHub" again.'
        );

        // The user clicked "Cancel" on GitHub's own authorization screen, or GitHub itself
        // errored — not a bug in this app, so redirect back with a friendly message rather
        // than a 4xx/5xx page.
        if ($request->query('error')) {
            return redirect('/settings/github?error='.urlencode($request->query('error_description', $request->query('error'))));
        }

        abort_unless($request->query('code'), 422, 'GitHub did not return an authorization code.');

        $token = $githubAuth->exchangeCodeForToken($request->query('code'));
        $user = $githubAuth->fetchAuthenticatedUser($token['access_token']);

        $connection = GithubConnection::updateOrCreate(
            ['github_user_id' => $user['id']],
            [
                'github_login' => $user['login'],
                'access_token' => $token['access_token'],
                'scopes' => $token['scope'] ?? null,
                'token_type' => $token['token_type'] ?? 'bearer',
            ]
        );

        return redirect("/settings/github?connection={$connection->id}");
    }
}
