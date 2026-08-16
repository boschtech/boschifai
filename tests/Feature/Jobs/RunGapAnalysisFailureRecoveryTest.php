<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Jobs\RunGapAnalysisJob;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression coverage for a real bug caught live: RunWorkspaceManager::prepare()'s `boschifai
 * init` failed (an unwritable local-repo bind mount) before StepExecutionService ever created a
 * RunStep row. That broke two things at once — the activity endpoint misreported the run as
 * "queued, no worker yet" instead of failed, and the retry button's route-model-bound `{step}`
 * had nothing to resolve `failed_step_id` to, so it 404'd. RunGapAnalysisJob now creates the
 * RunStep up front and fails it explicitly — this locks that behavior in.
 */
class RunGapAnalysisFailureRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workspace_prepare_failure_marks_the_run_and_step_failed_and_retry_does_not_404(): void
    {
        // A closure fakes every Process call, not just a pattern-matched subset — an
        // array-keyed fake only intercepts commands matching its own keys and lets anything
        // else (e.g. `git status` inside RunWorkspaceManager::statusPaths()) hit the real
        // filesystem, which isn't what this test wants to exercise.
        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_contains($command, 'boschifai init')) {
                return Process::result(exitCode: 1, errorOutput: 'Read-only file system (os error 30)');
            }

            return Process::result();
        });

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => sys_get_temp_dir(), 'base_branch' => 'main',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => RunState::Draft,
        ]);

        app()->call([new RunGapAnalysisJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('gap_analysis', $run->failed_step);
        $this->assertStringContainsString('Read-only file system', $run->error_message);

        $step = $run->steps()->where('key', 'gap_analysis')->first();
        $this->assertNotNull($step, 'Expected a RunStep row to exist even though prepare() failed before StepExecutionService ran.');
        $this->assertSame(RunStepStatus::Failed, $step->status);

        Queue::fake([RunGapAnalysisJob::class]);

        $this->postJson("/api/runs/{$run->id}/steps/{$step->id}/retry")
            ->assertOk();

        Queue::assertPushed(RunGapAnalysisJob::class);
    }
}
