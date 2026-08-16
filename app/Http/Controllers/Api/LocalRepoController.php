<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectLocalRepositoriesRequest;
use App\Http\Resources\RepoConfigResource;
use App\Models\RepoConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * "Connect Repo"'s local-filesystem section — see config/boschifai.php's `local_repos` docblock
 * for why every path here is resolved against a single configured root and rejected if it
 * escapes it (this app has no authentication, so an open "browse any directory" endpoint would
 * be a real disclosure risk).
 */
class LocalRepoController extends Controller
{
    public function browse(Request $request)
    {
        $root = $this->root();
        $target = $this->resolveWithinRoot($root, (string) $request->query('path', ''));

        abort_unless(is_dir($target), 422, 'Not a directory.');

        $connected = RepoConfig::whereNull('github_connection_id')
            ->get(['id', 'git_remote_path'])
            ->keyBy('git_remote_path');

        $entries = collect(scandir($target) ?: [])
            ->reject(fn ($name) => str_starts_with($name, '.'))
            ->map(function ($name) use ($target, $root, $connected) {
                $absolute = $target.'/'.$name;
                if (! is_dir($absolute)) {
                    return null;
                }

                $existing = $connected->get($absolute);

                return [
                    'name' => $name,
                    // Relative to root — what the browse/connect endpoints accept back, so the
                    // frontend never needs to know or send container-internal absolute paths.
                    'path' => ltrim(substr($absolute, strlen($root)), '/'),
                    'is_git_repo' => file_exists($absolute.'/.git'),
                    'connected' => $existing !== null,
                    // Mirrors GithubConnectionController::repositories()'s shape so the frontend
                    // can reuse the same remove-button logic for both.
                    'repo_config_id' => $existing?->id,
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values();

        return response()->json([
            'path' => ltrim(substr($target, strlen($root)), '/'),
            'entries' => $entries,
        ]);
    }

    public function connect(ConnectLocalRepositoriesRequest $request)
    {
        $root = $this->root();

        foreach ($request->validated('repositories') as $repo) {
            $absolute = $this->resolveWithinRoot($root, $repo['path']);

            abort_unless(
                file_exists($absolute.'/.git'),
                422,
                "'{$repo['path']}' doesn't look like a git repository (no .git found)."
            );

            $name = basename($absolute);

            $existing = RepoConfig::where('name', $name)->first();
            abort_if(
                $existing && $existing->git_remote_path !== $absolute,
                422,
                "A repository named '{$name}' is already connected to Boschifai from elsewhere — rename one before connecting both."
            );

            RepoConfig::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'git_remote_path' => $absolute,
                    'base_branch' => $this->detectDefaultBranch($absolute),
                    'github_connection_id' => null,
                    'github_owner' => null,
                ]
            );
        }

        return RepoConfigResource::collection(RepoConfig::whereNull('github_connection_id')->get());
    }

    private function root(): string
    {
        $root = realpath(config('boschifai.local_repos.root_path'));

        abort_unless($root, 500, 'Local repos root path ('.config('boschifai.local_repos.root_path').") doesn't exist — check BOSCHIFAI_LOCAL_REPOS_ROOT_PATH / the docker-compose bind mount.");

        return $root;
    }

    private function resolveWithinRoot(string $root, string $relativePath): string
    {
        $candidate = realpath($root.'/'.ltrim($relativePath, '/'));

        abort_if(
            $candidate === false || ! (str_starts_with($candidate, $root.'/') || $candidate === $root),
            404,
            'Path not found.'
        );

        return $candidate;
    }

    private function detectDefaultBranch(string $path): string
    {
        $result = Process::path($path)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);

        return $result->successful() ? trim($result->output()) : 'main';
    }
}
