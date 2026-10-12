<?php

namespace Tests\Feature\Jobs;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunArtifact;
use App\Models\RunStep;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RunTestCaseGenerationJob: the /boschifai-test-cases step, its transcript-reported validator
 * verdict, and the bounded NEEDS_FIXES self-retry loop.
 */
class RunTestCaseGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-test-case-gen-test-'.uniqid();
        File::ensureDirectoryExists($this->checkoutPath);
        config(['boschifai.claude.max_retries.test_case_generation' => 2]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    private function makeRun(array $attributes = []): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);

        return Run::create(array_merge([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'app/X.php',
            'state' => RunState::GapAnalysisReady,
        ], $attributes));
    }

    private function fakeClaudeSaying(string $text, int $exitCode = 0, string $stderr = ''): void
    {
        Process::fake(function ($process) use ($text, $exitCode, $stderr) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_starts_with($command, 'claude ')) {
                return Process::result(
                    output: json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => $text]]]])."\n",
                    errorOutput: $stderr,
                    exitCode: $exitCode,
                );
            }

            return Process::result();
        });
    }

    private function handle(Run $run): void
    {
        app()->call([new RunTestCaseGenerationJob($run->id), 'handle']);
    }

    public function test_an_approved_verdict_records_it_and_dispatches_code_generation(): void
    {
        Queue::fake();
        $this->fakeClaudeSaying("PASS: 12 | FAIL: 0\nVERDICT: APPROVED");

        $run = $this->makeRun();
        RunArtifact::makeFromContent($run->id, ArtifactKind::TestabilityReview, '.boschifai/testability_review_x.md', 'review')->save();

        $this->handle($run);

        $run->refresh();
        $this->assertSame('APPROVED', $run->test_case_verdict);
        $this->assertSame(RunState::GenerationRunning, $run->state);
        Queue::assertPushed(RunCodeGenerationJob::class, fn ($job) => $job->runId === $run->id);
        Queue::assertNotPushed(RunTestCaseGenerationJob::class);

        $this->assertSame(RunStepStatus::Succeeded, $run->steps()->where('key', 'test_case_generation')->first()->status);

        Process::assertRan(fn ($process) => is_array($process->command)
            && $process->command[0] === 'claude'
            && str_contains($process->command[2], '.boschifai/testability_review_x.md')
            && str_contains($process->command[2], "/boschifai-test-cases --file .boschifai/requirement_{$run->id}.md"));
    }

    public function test_a_missing_verdict_still_proceeds_to_code_generation(): void
    {
        Queue::fake();
        $this->fakeClaudeSaying('Generated test cases.');

        $run = $this->makeRun();

        $this->handle($run);

        $this->assertNull($run->fresh()->test_case_verdict);
        Queue::assertPushed(RunCodeGenerationJob::class);
    }

    public function test_needs_fixes_with_attempts_remaining_redispatches_itself(): void
    {
        Queue::fake();
        $this->fakeClaudeSaying('VERDICT: NEEDS_FIXES');

        $run = $this->makeRun();

        $this->handle($run);

        $run->refresh();
        $this->assertSame('NEEDS_FIXES', $run->test_case_verdict);
        $this->assertSame(RunState::GenerationRunning, $run->state);
        Queue::assertPushed(RunTestCaseGenerationJob::class, fn ($job) => $job->runId === $run->id);
        Queue::assertNotPushed(RunCodeGenerationJob::class);
    }

    public function test_needs_fixes_after_the_maximum_attempts_blocks_the_run_for_a_human(): void
    {
        Queue::fake();
        $this->fakeClaudeSaying('VERDICT: NEEDS_FIXES');

        $run = $this->makeRun();
        $step = RunStep::create(['run_id' => $run->id, 'key' => 'test_case_generation', 'status' => RunStepStatus::Succeeded]);
        // Two earlier attempts already recorded; this run is the third (1 + max_retries of 2).
        foreach ([1, 2] as $_) {
            $step->claudeInvocations()->create(['command' => '/boschifai-test-cases', 'prompt_text' => 'p']);
        }

        $this->handle($run);

        $run->refresh();
        $this->assertSame(RunState::GenerationBlocked, $run->state);
        $this->assertSame('Test-case validator still reports NEEDS_FIXES after 3 attempts.', $run->blocked_reason);
        Queue::assertNothingPushed();
    }

    public function test_a_failed_claude_invocation_fails_the_run(): void
    {
        Queue::fake();
        $this->fakeClaudeSaying('', exitCode: 1, stderr: 'boom');

        $run = $this->makeRun();

        $this->handle($run);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('test_case_generation', $run->failed_step);
        $this->assertStringContainsString('boom', $run->error_message);
        Queue::assertNothingPushed();
    }

    public function test_a_cancellation_requested_while_queued_marks_the_run_cancelled_without_invoking_claude(): void
    {
        Queue::fake();
        Process::fake();

        $run = $this->makeRun(['cancel_requested_at' => now()]);

        $this->handle($run);

        $this->assertSame(RunState::Cancelled, $run->fresh()->state);
        Process::assertNothingRan();
        Queue::assertNothingPushed();
    }

    public function test_a_cancellation_mid_invocation_stops_without_dispatching_anything(): void
    {
        Queue::fake();
        Process::fake();

        $run = $this->makeRun();

        // Simulates the invoker noticing the cancel flag mid-run (which a faked process can't).
        $this->app->instance(HeadlessClaudeInvoker::class, new class($run) extends HeadlessClaudeInvoker
        {
            public function __construct(private Run $run) {}

            public function invoke(string $worktreePath, string $prompt, string $transcriptPath, int $timeoutSeconds, callable $isCancelled, ?string $stepKey = null): ClaudeInvocationResult
            {
                $this->run->update(['cancel_requested_at' => now()]);

                return new ClaudeInvocationResult(
                    exitCode: 143, timedOut: false, rawTranscriptPath: $transcriptPath, durationMs: 5,
                    totalCostUsd: null, numTurns: null, stopReason: null, stderr: '', cancelled: true,
                );
            }
        });

        $this->handle($run);

        $run->refresh();
        $this->assertSame(RunState::Cancelled, $run->state);
        $this->assertNull($run->test_case_verdict);
        $this->assertSame(RunStepStatus::Cancelled, $run->steps()->where('key', 'test_case_generation')->first()->status);
        $this->assertSame(143, $run->steps()->first()->claudeInvocations()->first()->exit_code);
        Queue::assertNothingPushed();
    }
}
