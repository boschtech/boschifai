<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Jobs\RunLocalTestExecutionJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\TestExecutionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Distinct from retryStep (which only fires for a Failed run): a run's local_execution can
 * legitimately need re-testing even after it already "succeeded" — the motivating case was a
 * real app bug the generated test caught, fixed after the run had already recorded a stale
 * failing result. Only valid from local_execution_complete (Gate 2, not yet pushed).
 */
class RunRerunLocalExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(RunState $state): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => $state,
        ]);
    }

    public function test_rerun_dispatches_the_job_and_clears_the_stale_result(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        $run = $this->makeRun(RunState::LocalExecutionComplete);
        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 30, 'passed' => 28, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 21049,
        ]);

        $this->postJson("/api/runs/{$run->id}/local-execution/rerun")
            ->assertOk()
            ->assertJsonPath('data.state', 'local_execution_running')
            ->assertJsonPath('data.execution_result', null);

        $this->assertDatabaseMissing('test_execution_results', ['run_id' => $run->id]);
        Queue::assertPushed(RunLocalTestExecutionJob::class);
    }

    public function test_rerun_is_rejected_for_a_run_not_awaiting_gate_2(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        $run = $this->makeRun(RunState::Draft);

        $this->postJson("/api/runs/{$run->id}/local-execution/rerun")->assertStatus(422);

        Queue::assertNotPushed(RunLocalTestExecutionJob::class);
    }
}
