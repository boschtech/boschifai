<?php

namespace App\Services\Github;

use App\Models\RepoConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Classic OAuth App integration ("Authorized OAuth Apps" — the same mechanism VS Code's own
 * "Sign in with GitHub" uses), not a GitHub App installation. There is no native GitHub
 * repo-picker screen for this flow — the user grants scope-wide access (the `repo` scope, used
 * here, covers every repo they can see), and which of those Boschifai actually acts on is
 * entirely our own in-app selection (see GithubConnectionController::connectRepositories).
 */
class GithubOAuthService
{
    public function authorizeUrl(string $state): string
    {
        $clientId = config('boschifai.github.oauth_client_id');
        if (! $clientId) {
            throw new RuntimeException(
                'GitHub OAuth App is not configured — see config/boschifai.php (github.oauth_client_id).'
            );
        }

        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => url('/github/callback'),
            'scope' => 'repo',
            'state' => $state,
        ]);
    }

    /** @return array{access_token: string, token_type: string, scope: string} */
    public function exchangeCodeForToken(string $code): array
    {
        $clientId = config('boschifai.github.oauth_client_id');
        $clientSecret = config('boschifai.github.oauth_client_secret');
        if (! $clientId || ! $clientSecret) {
            throw new RuntimeException(
                'GitHub OAuth App is not configured — see config/boschifai.php (github.oauth_client_id / github.oauth_client_secret).'
            );
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post('https://github.com/login/oauth/access_token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => url('/github/callback'),
            ]);

        // GitHub returns HTTP 200 even for a bad/expired code — the failure is only visible in
        // the response body's `error` field, never a non-2xx status.
        if ($response->failed() || $response->json('error')) {
            throw new RuntimeException(
                'GitHub OAuth token exchange failed: '.($response->json('error_description') ?? $response->body())
            );
        }

        return $response->json();
    }

    /** @return array{id: int, login: string} */
    public function fetchAuthenticatedUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->acceptJson()->get('https://api.github.com/user');

        if ($response->failed()) {
            throw new RuntimeException('Fetching the authenticated GitHub user failed: '.$response->body());
        }

        return $response->json();
    }

    /** @return array<int, array{full_name:string,name:string,private:bool,default_branch:string}> */
    public function listUserRepositories(string $accessToken): array
    {
        $repos = [];
        $page = 1;

        do {
            $response = Http::withToken($accessToken)->acceptJson()->get('https://api.github.com/user/repos', [
                'affiliation' => 'owner,collaborator,organization_member',
                'per_page' => 100,
                'page' => $page,
            ]);

            if ($response->failed()) {
                throw new RuntimeException('Listing GitHub repositories failed: '.$response->body());
            }

            $batch = $response->json();
            $repos = [...$repos, ...$batch];
            $page++;
        } while (count($batch) === 100);

        return $repos;
    }

    /**
     * The single resolution point every GitHub-talking caller uses. Every RepoConfig must be
     * connected via the "Connect GitHub" OAuth flow — there is no other supported source of a
     * push/PR/CI-polling token.
     */
    public function tokenFor(RepoConfig $repo): string
    {
        if ($repo->github_connection_id === null) {
            throw new RuntimeException(
                "'{$repo->display_name}' has no GitHub account connected — connect it via \"Connect GitHub\" before running the pipeline against it."
            );
        }

        return $repo->githubConnection->access_token;
    }
}
