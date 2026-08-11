<?php

use App\Http\Controllers\Api\GithubConnectionController;
use App\Http\Controllers\Api\RepoConfigController;
use App\Http\Controllers\Api\RunApprovalController;
use App\Http\Controllers\Api\RunController;
use Illuminate\Support\Facades\Route;

Route::get('/runs', [RunController::class, 'index']);
Route::post('/runs', [RunController::class, 'store']);
Route::get('/runs/{run}', [RunController::class, 'show']);
Route::delete('/runs/{run}', [RunController::class, 'destroy']);
Route::get('/runs/{run}/activity', [RunController::class, 'activity']);
Route::post('/runs/{run}/steps/{step}/retry', [RunController::class, 'retryStep']);
Route::post('/runs/{run}/cancel', [RunController::class, 'cancel']);

Route::post('/runs/{run}/approvals/gap-analysis', [RunApprovalController::class, 'gapAnalysis']);
Route::post('/runs/{run}/approvals/push', [RunApprovalController::class, 'push']);

Route::get('/repo-configs', [RepoConfigController::class, 'index']);
Route::delete('/repo-configs/{repoConfig}', [RepoConfigController::class, 'destroy']);

Route::get('/github/authorize-url', [GithubConnectionController::class, 'authorizeUrl']);
Route::get('/github/connections', [GithubConnectionController::class, 'connections']);
Route::get('/github/connections/{connection}/repositories', [GithubConnectionController::class, 'repositories']);
Route::post('/github/connections/{connection}/repositories', [GithubConnectionController::class, 'connectRepositories']);
