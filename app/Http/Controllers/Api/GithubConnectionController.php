<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectGithubRepositoriesRequest;
use App\Http\Resources\GithubConnectionResource;
use App\Http\Resources\RepoConfigResource;
use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Services\Github\GithubOAuthService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GithubConnectionController extends Controller
{
    /**
     * A single-use nonce (Cache, not session — the `api` middleware group has no session by
     * default) guarding the redirect-to-GitHub-and-back round trip against a blind link being
     * used to attach a connection the user never actually initiated from here.
     */
    public function authorizeUrl(GithubOAuthService $githubAuth)
    {
        abort_unless(
            config('boschifai.github.oauth_client_id'),
            422,
            'GitHub OAuth App is not configured — see config/boschifai.php (github.oauth_client_id).'
        );

        $state = Str::random(40);
        Cache::put("github:oauth_state:{$state}", true, now()->addMinutes(10));

        return JsonResource::make(['url' => $githubAuth->authorizeUrl($state)]);
    }

    public function connections()
    {
        return GithubConnectionResource::collection(
            GithubConnection::withCount('repoConfigs')->latest()->get()
        );
    }

    public function repositories(GithubConnection $connection, GithubOAuthService $githubAuth)
    {
        $repos = $githubAuth->listUserRepositories($connection->access_token);
        $connected = RepoConfig::where('github_connection_id', $connection->id)
            ->get(['id', 'name', 'docker_image'])
            ->keyBy('name');

        return response()->json([
            'data' => collect($repos)->map(function ($r) use ($connected) {
                $existing = $connected->get($r['name']);

                return [
                    'full_name' => $r['full_name'],
                    'name' => $r['name'],
                    'private' => $r['private'],
                    'default_branch' => $r['default_branch'],
                    'connected' => $existing !== null,
                    // Needed so the UI can offer a "remove" action per repo — see
                    // RepoConfigController::destroy.
                    'repo_config_id' => $existing?->id,
                    // Pre-fills the picker's docker-image input on repeat visits, rather than
                    // forcing it to be re-typed every time the repo list is reopened.
                    'docker_image' => $existing?->docker_image,
                ];
            })->values(),
        ]);
    }

    public function connectRepositories(ConnectGithubRepositoriesRequest $request, GithubConnection $connection)
    {
        foreach ($request->validated('repositories') as $repo) {
            [$owner, $name] = explode('/', $repo['full_name'], 2);

            // Also blocks colliding with a pre-existing, non-GitHub RepoConfig — silently
            // repointing some other row's git_remote_path at a GitHub clone URL would break
            // whatever that row was already being used for.
            $existing = RepoConfig::where('name', $name)->first();
            abort_if(
                $existing && $existing->github_connection_id !== $connection->id,
                422,
                "A repository named '{$name}' is already connected to Boschifai from elsewhere — rename one before connecting both."
            );

            RepoConfig::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $repo['full_name'],
                    'git_remote_path' => "https://github.com/{$owner}/{$name}.git",
                    'base_branch' => $repo['default_branch'],
                    'github_owner' => $owner,
                    'github_connection_id' => $connection->id,
                    'docker_image' => $repo['docker_image'] ?? null,
                ]
            );
        }

        return RepoConfigResource::collection($connection->repoConfigs()->get());
    }
}
