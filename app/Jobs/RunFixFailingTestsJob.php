<?php

namespace App\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Triggered by "Fix failing tests" at Gate 2 — asks Claude to edit the already-generated test
 * file in place so its failures pass, then re-runs local execution, same as RunCodeGenerationJob
 * does after a fresh generation.
 *
 * Unlike RunCodeGenerationJob, success here is NOT gated on StepExecutionService detecting a new
 * artifact via git-status diff: the file being fixed already exists and is already untracked from
 * the original code_generation step, so it shows up in both the before/after status snapshots and
 * never looks "new" to GitStatusDiffCollector::newPaths() even though its content changed. The
 * Claude invocation succeeding is the only signal available.
 */
class RunFixFailingTestsJob implements ShouldQueue
{
    use Queueable;

    // See RunCodeGenerationJob's $timeout comment for why this override is not optional.
    public int $timeout = 1000;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(StepExecutionService $steps, PromptBuilder $prompts, CancellationChecker $cancellation): void
    {
        $run = Run::findOrFail($this->runId);

        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::FixFailingTests->value, 'Cancelled by user before this step started.');

            return;
        }

        // Created up front (same pattern as RunGapAnalysisJob/RunLocalTestExecutionJob), not
        // left for StepExecutionService to create once the Claude invocation actually starts —
        // confirmed as a real bug live, twice: first an exception while just building the
        // prompt, then (after that was fixed) an uncaught `git status` timeout INSIDE
        // StepExecutionService::run() itself (RunWorkspaceManager::statusPaths() — see its own
        // updated timeout comment). Either kind of failure, anywhere before Claude actually
        // finishes, must not leave the run silently stuck in generation_running forever with no
        // RunStep row for the activity endpoint or retry button to find, and no error surfaced
        // anywhere but the log — so everything through the $steps->run() call is now inside one
        // try/catch, not just the prompt-building step.
        $step = RunStep::updateOrCreate(
            ['run_id' => $run->id, 'key' => RunStepKey::FixFailingTests->value],
            ['status' => RunStepStatus::Running, 'started_at' => now(), 'finished_at' => null, 'error_message' => null]
        );

        try {
            $failingTests = collect($run->executionResult?->tests ?? [])
                ->where('status', 'failed')
                ->values()
                ->all();

            $prompt = $prompts->fixFailingTests($run->generated_file_path, $failingTests);

            $outcome = $steps->run(
                $run,
                RunStepKey::FixFailingTests->value,
                $prompt,
                config('boschifai.claude.timeouts.fix_failing_tests'),
            );
        } catch (\Throwable $e) {
            $step->update(['status' => RunStepStatus::Failed, 'finished_at' => now(), 'error_message' => $e->getMessage()]);
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::FixFailingTests->value,
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        if ($outcome->claudeResult->cancelled) {
            return;
        }

        if (! $outcome->claudeResult->succeeded()) {
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::FixFailingTests->value,
                'error_message' => $outcome->claudeResult->stderr,
            ]);

            return;
        }

        // The prior (failing) result is stale now that the test file has changed — cleared here
        // rather than left to RunLocalTestExecutionJob, matching rerunLocalExecution's own order.
        $run->executionResult()->delete();
        $run->update(['state' => RunState::LocalExecutionRunning]);

        RunLocalTestExecutionJob::dispatch($run->id);
    }
}
