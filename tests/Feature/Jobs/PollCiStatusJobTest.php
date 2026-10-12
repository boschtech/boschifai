<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Jobs\PollCiStatusJob;
use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\TestExecutionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PollCiStatusJob + CiPollingService together, with GitHub's check-runs API faked.
 */
class PollCiStatusJobTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_RUNS_URL = 'https://api.github.com/repos/acme/backend/commits/abc123/check-runs';

    private function makeRun(array $attributes = []): Run
    {
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'acme', 'access_token' => 'gho_test',
        ]);
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id, 'github_owner' => 'acme',
        ]);

        return Run::create(array_merge([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => RunState::CiPending,
            'head_sha' => 'abc123',
            'testability_score' => 80,
            'test_case_verdict' => 'APPROVED',
        ], $attributes));
    }

    private function handle(PollCiStatusJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_a_concluded_check_records_the_result_and_reports_with_a_confidence_score(): void
    {
        Queue::fake();
        Http::fake([self::CHECK_RUNS_URL => Http::response(['check_runs' => [
            ['id' => 1, 'name' => 'lint', 'status' => 'completed', 'conclusion' => 'failure'],
            ['id' => 987, 'name' => 'Sonar Scan / test', 'status' => 'completed', 'conclusion' => 'success'],
        ]])]);

        $run = $this->makeRun();
        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 10, 'passed' => 8, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 1000,
        ]);

        $this->handle(new PollCiStatusJob($run->id));

        $this->assertDatabaseHas('ci_check_results', [
            'run_id' => $run->id,
            'workflow_name' => 'Sonar Scan',
            'check_run_id' => '987',
            'conclusion' => 'success',
        ]);

        $run->refresh();
        $this->assertSame(RunState::ReportReady, $run->state);
        // (80*25 + 100*20 + 80*25 + 100*30) / 100 = 90
        $this->assertSame(90, $run->confidence_score);
        $this->assertSame(100, $run->confidence_breakdown['ci']);
        Queue::assertNothingPushed();

        Http::assertSent(fn (Request $request) => $request->url() === self::CHECK_RUNS_URL
            && $request->hasHeader('Authorization', 'Bearer gho_test'));
    }

    public function test_an_in_progress_check_requeues_with_the_elapsed_time_advanced(): void
    {
        Queue::fake();
        config(['boschifai.github.ci_poll_interval_seconds' => 30, 'boschifai.github.ci_poll_timeout_seconds' => 1800]);
        Http::fake([self::CHECK_RUNS_URL => Http::response(['check_runs' => [
            ['id' => 5, 'name' => 'test', 'status' => 'in_progress', 'conclusion' => null],
        ]])]);

        $run = $this->makeRun();

        $this->handle(new PollCiStatusJob($run->id, 60));

        Queue::assertPushed(PollCiStatusJob::class, fn (PollCiStatusJob $job) => $job->runId === $run->id
            && $job->elapsedSeconds === 90
            && $job->delay !== null);
        $this->assertDatabaseCount('ci_check_results', 0);
        $this->assertNotSame(RunState::ReportReady, $run->fresh()->state);
    }

    public function test_a_check_that_has_not_appeared_yet_also_requeues(): void
    {
        Queue::fake();
        Http::fake([self::CHECK_RUNS_URL => Http::response(['check_runs' => [
            ['id' => 5, 'name' => 'deploy', 'status' => 'completed', 'conclusion' => 'success'],
        ]])]);

        $run = $this->makeRun();

        $this->handle(new PollCiStatusJob($run->id));

        Queue::assertPushed(PollCiStatusJob::class, 1);
        $this->assertDatabaseCount('ci_check_results', 0);
    }

    public function test_polling_past_the_timeout_fails_the_run_instead_of_requeuing(): void
    {
        Queue::fake();
        config(['boschifai.github.ci_poll_timeout_seconds' => 1800]);
        Http::fake([self::CHECK_RUNS_URL => Http::response(['check_runs' => []])]);

        $run = $this->makeRun();

        $this->handle(new PollCiStatusJob($run->id, 1800));

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('ci_poll', $run->failed_step);
        $this->assertSame('CI check did not conclude within 1800s.', $run->error_message);
        Queue::assertNothingPushed();
    }

    public function test_a_cancelled_run_stops_polling_without_calling_github(): void
    {
        Queue::fake();
        Http::fake();

        $run = $this->makeRun(['cancel_requested_at' => now()]);

        $this->handle(new PollCiStatusJob($run->id));

        $this->assertSame(RunState::Cancelled, $run->fresh()->state);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_github_api_failure_surfaces_as_an_exception(): void
    {
        Queue::fake();
        Http::fake([self::CHECK_RUNS_URL => Http::response('Bad credentials', 401)]);

        $run = $this->makeRun();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub check-runs poll failed: Bad credentials');

        $this->handle(new PollCiStatusJob($run->id));
    }

    public function test_a_repo_without_a_github_connection_cannot_be_polled(): void
    {
        Queue::fake();
        Http::fake();

        $run = $this->makeRun();
        $run->repoConfig->update(['github_connection_id' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'acme/backend' has no GitHub account connected");

        try {
            $this->handle(new PollCiStatusJob($run->id));
        } finally {
            Http::assertNothingSent();
        }
    }
}
