<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RepoConfigResource;
use App\Models\RepoConfig;

class RepoConfigController extends Controller
{
    public function index()
    {
        return RepoConfigResource::collection(RepoConfig::orderBy('display_name')->get());
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
