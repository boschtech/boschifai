<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectGithubRepositoriesRequest;
use App\Http\Resources\GithubConnectionResource;
use App\Http\Resources\RepoConfigResource;
use App\Models\ExcludedGithubOrganization;
use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Services\Github\GithubOAuthService;

class GithubConnectionController extends Controller
{
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
            ->get(['id', 'name'])
            ->keyBy('name');
        $excludedOrgs = $connection->excludedOrganizations()->pluck('organization_login');

        return response()->json([
            'data' => collect($repos)
                // A "deleted" organisation (ExcludedGithubOrganization) must not just lose its
                // connected repos — it has to stop being offered at all, or it'd reappear on
                // every subsequent fetch since GitHub itself has no concept of the deletion.
                ->reject(fn ($r) => $excludedOrgs->contains(explode('/', $r['full_name'], 2)[0]))
                ->map(function ($r) use ($connected) {
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
                    ];
                })->values(),
            'excluded_organizations' => $excludedOrgs->values(),
        ]);
    }

    /**
     * Bulk-removes every repo this connection has under $organization AND remembers the
     * organisation as excluded, so repositories() stops offering it — see that method's comment.
     * Reversible via restoreOrganization(), since this is otherwise a one-way door: there's no
     * other way back in short of disconnecting and re-authorizing the whole GitHub account.
     */
    public function excludeOrganization(GithubConnection $connection, string $organization)
    {
        RepoConfig::where('github_connection_id', $connection->id)
            ->where('github_owner', $organization)
            ->delete();

        ExcludedGithubOrganization::firstOrCreate([
            'github_connection_id' => $connection->id,
            'organization_login' => $organization,
        ]);

        return response()->noContent();
    }

    public function restoreOrganization(GithubConnection $connection, string $organization)
    {
        $connection->excludedOrganizations()->where('organization_login', $organization)->delete();

        return response()->noContent();
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
                ]
            );
        }

        return RepoConfigResource::collection($connection->repoConfigs()->get());
    }
}
