<?php

use App\Http\Controllers\Api\GithubConnectionController;
use App\Http\Controllers\Api\LocalRepoController;
use App\Http\Controllers\Api\RepoConfigController;
use App\Http\Controllers\Api\RunApprovalController;
use App\Http\Controllers\Api\RunController;
use App\Http\Controllers\Api\UsageController;
use Illuminate\Support\Facades\Route;

Route::get('/runs', [RunController::class, 'index']);
Route::post('/runs', [RunController::class, 'store']);
Route::get('/runs/{run}', [RunController::class, 'show']);
Route::delete('/runs/{run}', [RunController::class, 'destroy']);
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
Route::delete('/repo-configs/{repoConfig}', [RepoConfigController::class, 'destroy']);

Route::get('/github/connections', [GithubConnectionController::class, 'connections']);
Route::get('/github/connections/{connection}/repositories', [GithubConnectionController::class, 'repositories']);
Route::post('/github/connections/{connection}/repositories', [GithubConnectionController::class, 'connectRepositories']);
Route::delete('/github/connections/{connection}/organizations/{organization}', [GithubConnectionController::class, 'excludeOrganization']);
Route::post('/github/connections/{connection}/organizations/{organization}/restore', [GithubConnectionController::class, 'restoreOrganization']);

Route::get('/local-repos/browse', [LocalRepoController::class, 'browse']);
Route::post('/local-repos/connect', [LocalRepoController::class, 'connect']);

Route::get('/usage/tokens-this-month', [UsageController::class, 'tokensThisMonth']);
Route::get('/usage/tokens-this-month/details', [UsageController::class, 'tokensThisMonthDetails']);
