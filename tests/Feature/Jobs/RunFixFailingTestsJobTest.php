<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Jobs\RunFixFailingTestsJob;
use App\Jobs\RunLocalTestExecutionJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\TestExecutionResult;
use App\Services\ClaudeRunner\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Unlike RunCodeGenerationJob, success here is NOT gated on detecting a new artifact via
 * git-status diff — the file being fixed already exists (already untracked from the original
 * code_generation step), so it never looks "new" to GitStatusDiffCollector even though its
 * content changed. Only the Claude invocation itself succeeding matters.
 */
class RunFixFailingTestsJobTest extends TestCase
{
    use RefreshDatabase;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-fix-failing-tests-test-'.uniqid();
        File::ensureDirectoryExists($this->checkoutPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'generated_file_path' => 'tests/Feature/XTest.php',
            'state' => RunState::GenerationRunning,
        ]);
    }

    public function test_a_successful_fix_references_the_failure_deletes_the_stale_result_and_dispatches_local_execution(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        Process::fake(fn () => Process::result());

        $run = $this->makeRun();
        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 13, 'passed' => 11, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 13439,
            'tests' => [
                ['name' => 'XTest::it_passes', 'status' => 'passed', 'message' => null],
                ['name' => 'XTest::it_fails', 'status' => 'failed', 'message' => 'Expected true, got false.'],
            ],
        ]);

        app()->call([new RunFixFailingTestsJob($run->id), 'handle']);

        Process::assertRan(function ($process) {
            $command = $process->command;

            return is_array($command)
                && ($command[0] ?? null) === 'claude'
                && ($command[1] ?? null) === '-p'
                && str_contains($command[2] ?? '', 'tests/Feature/XTest.php')
                && str_contains($command[2] ?? '', 'XTest::it_fails')
                && str_contains($command[2] ?? '', 'Expected true, got false.')
                // The passing test's name must not leak in as if it were a failure to fix.
                && ! str_contains($command[2] ?? '', 'XTest::it_passes');
        });

        $this->assertDatabaseMissing('test_execution_results', ['run_id' => $run->id]);
        $this->assertSame(RunState::LocalExecutionRunning, $run->fresh()->state);
        Queue::assertPushed(RunLocalTestExecutionJob::class);
    }

    public function test_a_failed_claude_invocation_marks_the_run_failed(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        // A closure fakes every Process call, not just a pattern-matched subset — only the
        // `claude` invocation itself should fail here; `git status` (called first, inside
        // StepExecutionService::run()) must still succeed or the job fails for the wrong reason.
        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_starts_with($command, 'claude ')) {
                return Process::result(errorOutput: 'boom', exitCode: 1);
            }

            return Process::result();
        });

        $run = $this->makeRun();
        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 13, 'passed' => 11, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 13439,
        ]);

        app()->call([new RunFixFailingTestsJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('fix_failing_tests', $run->failed_step);
        $this->assertStringContainsString('boom', $run->error_message);
        // The stale result is left in place on failure — nothing about the local test outcome
        // changed, so there's nothing to invalidate.
        $this->assertDatabaseHas('test_execution_results', ['run_id' => $run->id]);
        Queue::assertNotPushed(RunLocalTestExecutionJob::class);
    }

    /**
     * Regression coverage for a real incident caught live: an exception thrown while just
     * building the prompt (before StepExecutionService ever runs, so no RunStep row exists yet)
     * left a real run silently stuck in generation_running indefinitely — nothing marked it
     * failed, the activity endpoint reported "queued, no worker" forever, and the retry button
     * had no failed_step_id to resolve. Mirrors RunGapAnalysisFailureRecoveryTest's own case for
     * an equivalent pre-StepExecutionService failure in that job.
     */
    public function test_an_unexpected_exception_before_step_execution_marks_the_run_and_step_failed(): void
    {
        Queue::fake([RunLocalTestExecutionJob::class]);
        Process::fake(fn () => Process::result());

        $this->app->bind(PromptBuilder::class, fn () => new class extends PromptBuilder
        {
            public function fixFailingTests(string $generatedFilePath, array $failingTests): string
            {
                throw new \RuntimeException('boom during prompt building');
            }
        });

        $run = $this->makeRun();
        TestExecutionResult::create([
            'run_id' => $run->id, 'total' => 13, 'passed' => 11, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 13439,
        ]);

        app()->call([new RunFixFailingTestsJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('fix_failing_tests', $run->failed_step);
        $this->assertStringContainsString('boom during prompt building', $run->error_message);

        $step = $run->steps()->where('key', 'fix_failing_tests')->first();
        $this->assertNotNull($step, 'Expected a RunStep row to exist even though the failure happened before StepExecutionService ran.');
        $this->assertSame(RunStepStatus::Failed, $step->status);

        Queue::assertNotPushed(RunLocalTestExecutionJob::class);
    }
}
