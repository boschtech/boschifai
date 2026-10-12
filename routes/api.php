<?php

use App\Http\Controllers\Api\GithubConnectionController;
use App\Http\Controllers\Api\InvitationAcceptController;
use App\Http\Controllers\Api\LocalRepoController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\RepoConfigController;
use App\Http\Controllers\Api\RunApprovalController;
use App\Http\Controllers\Api\RunController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\WorkspaceInvitationController;
use App\Http\Controllers\Api\WorkspaceMemberController;
use App\Http\Controllers\Api\WorkspaceSwitchController;
use Illuminate\Support\Facades\Route;

// Opening and accepting an invitation link works signed in or not (see InvitationAcceptController).
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/invitations/{token}', [InvitationAcceptController::class, 'show']);
    Route::post('/invitations/{token}/accept', [InvitationAcceptController::class, 'accept']);
});

// Every other API route requires a signed-in user (Sanctum session — see bootstrap/app.php) who belongs
// to a workspace; all data is scoped to their current workspace (see BelongsToWorkspace).
Route::middleware(['auth:sanctum', 'workspace'])->group(function () {
    Route::get('/me', MeController::class);
    Route::post('/workspaces/{workspace}/switch', WorkspaceSwitchController::class);

    // Day-to-day work: any member of the workspace.
    Route::get('/runs', [RunController::class, 'index']);
    Route::post('/runs', [RunController::class, 'store']);
    Route::get('/runs/{run}', [RunController::class, 'show']);
    Route::get('/runs/{run}/activity', [RunController::class, 'activity']);
    Route::post('/runs/{run}/steps/{step}/retry', [RunController::class, 'retryStep']);
    Route::post('/runs/{run}/local-execution/rerun', [RunController::class, 'rerunLocalExecution']);
    Route::post('/runs/{run}/local-execution/fix-failing-tests', [RunController::class, 'fixFailingTests']);
    Route::get('/runs/{run}/coverage-report/{path}', [RunController::class, 'coverageReport'])->where('path', '.*');
    Route::post('/runs/{run}/cancel', [RunController::class, 'cancel']);
    Route::post('/runs/{run}/archive', [RunController::class, 'archive']);
    Route::post('/runs/{run}/unarchive', [RunController::class, 'unarchive']);

    Route::post('/runs/{run}/approvals/gap-analysis', [RunApprovalController::class, 'gapAnalysis']);
    Route::post('/runs/{run}/approvals/push', [RunApprovalController::class, 'push']);

    Route::get('/repo-configs', [RepoConfigController::class, 'index']);
    Route::get('/repo-configs/{repoConfig}/browse-files', [RepoConfigController::class, 'browseFiles']);

    Route::get('/usage/tokens-this-month', [UsageController::class, 'tokensThisMonth']);
    Route::get('/usage/tokens-this-month/details', [UsageController::class, 'tokensThisMonthDetails']);

    // Managing the workspace — its name, members, repositories and GitHub connections: admins
    // and owners only.
    Route::middleware('workspace.manage')->group(function () {
        Route::get('/workspace', [WorkspaceController::class, 'show']);
        Route::patch('/workspace', [WorkspaceController::class, 'update']);
        Route::patch('/workspace/members/{member}', [WorkspaceMemberController::class, 'update'])->whereNumber('member');
        Route::delete('/workspace/members/{member}', [WorkspaceMemberController::class, 'destroy'])->whereNumber('member');
        Route::post('/workspace/invitations', [WorkspaceInvitationController::class, 'store'])->middleware('throttle:30,60');
        Route::delete('/workspace/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy']);

        Route::delete('/runs/{run}', [RunController::class, 'destroy']);
        Route::delete('/repo-configs/{repoConfig}', [RepoConfigController::class, 'destroy']);

        Route::get('/github/connections', [GithubConnectionController::class, 'connections']);
        Route::get('/github/connections/{connection}/repositories', [GithubConnectionController::class, 'repositories']);
        Route::post('/github/connections/{connection}/repositories', [GithubConnectionController::class, 'connectRepositories']);
        Route::delete('/github/connections/{connection}/organizations/{organization}', [GithubConnectionController::class, 'excludeOrganization']);
        Route::post('/github/connections/{connection}/organizations/{organization}/restore', [GithubConnectionController::class, 'restoreOrganization']);

        Route::get('/local-repos/browse', [LocalRepoController::class, 'browse']);
        Route::post('/local-repos/connect', [LocalRepoController::class, 'connect']);
    });
});
