<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RepoConfigResource;
use App\Models\RepoConfig;
use App\Services\Sandbox\RepoCheckoutManager;
use Illuminate\Http\Request;

class RepoConfigController extends Controller
{
    /**
     * Dirs/files this browser never surfaces — heavy dependency trees or generated noise no
     * one would ever pick as a requirement's target file, not a security boundary (unlike
     * LocalRepoController's root-escape guard, which IS one).
     */
    private const EXCLUDED_ENTRIES = ['.git', '.boschifai', 'vendor', 'node_modules'];

    public function index()
    {
        return RepoConfigResource::collection(RepoConfig::orderBy('display_name')->get());
    }

    /**
     * Backs the "Browse files…" picker on the run-create form's Target file field — lists a
     * connected repo's OWN checkout (RepoCheckoutManager::path(), same one Claude explores and
     * generates into), not the local-repos root LocalRepoController browses to CONNECT a repo in
     * the first place. Deliberately does NOT call ensureReady(): that clones/resets a
     * GitHub-connected repo and belongs to `worker`'s own job pipeline, not a web request a user
     * is idly clicking through — a repo that's never had a Run yet simply has nothing to browse.
     */
    public function browseFiles(RepoConfig $repoConfig, Request $request, RepoCheckoutManager $checkouts)
    {
        $root = realpath($checkouts->path($repoConfig));

        abort_unless($root, 422, "'{$repoConfig->display_name}' hasn't been checked out yet — submit a run against it first, then its files will be browsable here.");

        $target = $this->resolveWithinRoot($root, (string) $request->query('path', ''));
        abort_unless(is_dir($target), 404, 'Path not found.');

        $entries = collect(scandir($target) ?: [])
            ->reject(fn ($name) => str_starts_with($name, '.') || in_array($name, self::EXCLUDED_ENTRIES, true))
            ->map(function ($name) use ($target, $root) {
                $absolute = $target.'/'.$name;

                return [
                    'name' => $name,
                    // Relative to the checkout root — what StoreRunRequest's target_file_path
                    // expects, so the frontend never needs to know the container-internal
                    // absolute path.
                    'path' => ltrim(substr($absolute, strlen($root)), '/'),
                    'type' => is_dir($absolute) ? 'dir' : 'file',
                ];
            })
            // Dirs first, then files, alphabetically within each — standard file-browser order.
            // 'asc' on type, not 'desc': it's a plain string sort, and "dir" < "file"
            // lexicographically, so ascending is what actually puts directories first.
            ->sortBy([['type', 'asc'], ['name', 'asc']])
            ->values();

        return response()->json([
            'path' => ltrim(substr($target, strlen($root)), '/'),
            'entries' => $entries,
        ]);
    }

    /**
     * Same realpath()-based escape guard LocalRepoController::resolveWithinRoot() already uses —
     * this app has no authentication (see README), so a "browse any path the caller sends" bug
     * would be a real disclosure risk.
     */
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

    /**
     * "Remove" only ever means Boschifai stops treating this repo as a run target — it does
     * NOT revoke or narrow the underlying GitHub OAuth grant (classic OAuth Apps don't support
     * per-repo revocation; that's an inherent tradeoff of this flow vs. a GitHub App
     * installation, see GithubOAuthService's own doc comment). Blocked, not cascaded, when
     * runs already reference this repo — runs.repo_config_id has no ON DELETE clause (defaults
     * to RESTRICT), and even if it didn't, silently deleting a repo out from under existing
     * run history would break the audit trail the whole pipeline is built to preserve.
     */
    public function destroy(RepoConfig $repoConfig)
    {
        $runCount = $repoConfig->runs()->count();
        abort_if(
            $runCount > 0,
            422,
            "Can't remove '{$repoConfig->display_name}' — it has {$runCount} existing run(s) referencing it. Delete those runs first if you really want to remove this repository."
        );

        $repoConfig->delete();

        return response()->noContent();
    }
}
