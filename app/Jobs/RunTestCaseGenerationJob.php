<?php

namespace App\Jobs;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Models\Run;
use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Plan §4 step 4. Runs after Gate 1 approval. `/boschifai-test-cases` includes its own Task-tool
 * validator (functionally the "reviewer" step the original ask wanted) — its verdict is
 * parsed from the transcript rather than re-implemented here. If it reports NEEDS_FIXES after
 * boschifai.claude.max_retries.test_case_generation attempts (mirrors boschifai-crew's own
 * two-attempt fix-loop rule), the run is blocked for a human rather than looping indefinitely.
 */
class RunTestCaseGenerationJob implements ShouldQueue
{
    use Queueable;

    // Confirmed as a real, previously undiscovered bug via live testing: Laravel's queue
    // worker kills a job after 60s by default (`queue:work`'s own --timeout), independent of
    // any internal Process::timeout() inside HeadlessClaudeInvoker. Without this override, a
    // real ~15-minute /boschifai-test-cases invocation (includes its own Task-tool validator) would
    // be silently SIGKILLed by the worker long before its own 900s budget. Set comfortably
    // above boschifai.claude.timeouts.test_case_generation. $tries=1 because retries here are
    // a human's explicit choice (RunController::retryStep) — see plan §3's rationale, this
    // job's own manual re-dispatch loop for NEEDS_FIXES is a distinct mechanism from Laravel's
    // automatic job retry and must not be conflated with it.
    public int $timeout = 1000;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(
        StepExecutionService $steps,
        PromptBuilder $prompts,
        ClaudeTranscriptParser $transcriptParser,
        CancellationChecker $cancellation,
    ): void {
        $run = Run::findOrFail($this->runId);

        // Also catches the NEEDS_FIXES self-retry path (self::dispatch below) — a cancellation
        // requested while a retry attempt was queued is caught here on that retry's own entry.
        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::TestCaseGeneration->value, 'Cancelled by user before this step started.');

            return;
        }

        $run->update(['state' => RunState::GenerationRunning]);

        $requirementRelativePath = ".boschifai/requirement_{$run->id}.md";
        $reviewArtifact = $run->artifactOfKind(ArtifactKind::TestabilityReview->value);

        $outcome = $steps->run(
            $run,
            RunStepKey::TestCaseGeneration->value,
            $prompts->testCaseGeneration($requirementRelativePath, $reviewArtifact?->relative_path ?? ''),
            config('boschifai.claude.timeouts.test_case_generation'),
        );

        if ($outcome->claudeResult->cancelled) {
            return;
        }

        if (! $outcome->claudeResult->succeeded()) {
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::TestCaseGeneration->value,
                'error_message' => $outcome->claudeResult->stderr,
            ]);

            return;
        }

        $verdict = $transcriptParser->extractTestCaseVerdict($outcome->claudeResult->rawTranscriptPath);
        $run->update(['test_case_verdict' => $verdict]);

        if ($verdict === 'NEEDS_FIXES') {
            $step = $run->stepByKey(RunStepKey::TestCaseGeneration->value);
            $attempts = $step?->claudeInvocations()->count() ?? 1;
            $maxAttempts = 1 + (int) config('boschifai.claude.max_retries.test_case_generation');

            if ($attempts < $maxAttempts) {
                self::dispatch($run->id);

                return;
            }

            $run->update([
                'state' => RunState::GenerationBlocked,
                'blocked_reason' => "Test-case validator still reports NEEDS_FIXES after {$attempts} attempts.",
            ]);

            return;
        }

        RunCodeGenerationJob::dispatch($run->id);
    }
}
