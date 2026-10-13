<?php

namespace App\Services\Pipeline;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Models\CreditLedgerEntry;
use App\Models\Run;
use App\Models\RunArtifact;
use App\Models\RunStep;
use App\Services\ArtifactCollection\GitStatusDiffCollector;
use App\Services\Billing\InsufficientCredits;
use App\Services\Billing\StepBilling;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Services\ClaudeRunner\Credentials\ClaudeCredentialResolver;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Support\Facades\File;

/**
 * Shared "invoke Claude for one pipeline step, collect whatever it produced" logic used by
 * every Claude-driven job (RunGapAnalysisJob, RunTestPlanJob, RunTestCaseGenerationJob,
 * RunCodeGenerationJob) — kept in one place so the git-status-diff artifact discovery (plan §3)
 * and RunStep/ClaudeInvocation bookkeeping isn't duplicated four times.
 */
class StepExecutionService
{
    public function __construct(
        private RunWorkspaceManager $workspace,
        private HeadlessClaudeInvoker $invoker,
        private GitStatusDiffCollector $diffCollector,
        private CancellationChecker $cancellation,
        private ClaudeCredentialResolver $credentials,
        private StepBilling $billing,
    ) {}

    public function run(Run $run, string $stepKey, string $prompt, int $timeoutSeconds): StepOutcome
    {
        // Pre-check before doing anything: covers cancelling a run while it's still queued
        // (dispatched but no worker free yet) or in the brief gap between pipeline steps —
        // without this, the earliest a cancellation could take effect would be mid-invocation,
        // wasting a checkout-prep/API call that was already known to be unwanted.
        if ($this->cancellation->isRequested($run->id)) {
            $this->cancellation->markCancelled($run, $stepKey, 'Cancelled by user before this step started.');

            return new StepOutcome(ClaudeInvocationResult::cancelledBeforeStart(), collect());
        }

        try {
            $hold = $this->billing->begin($run, $stepKey);
        } catch (InsufficientCredits $e) {
            return $this->awaitCredits($run, $stepKey, $e);
        }

        try {
            return $this->runHeld($run, $stepKey, $prompt, $timeoutSeconds, $hold);
        } catch (\Throwable $e) {
            // Crashed before the step could be settled: nothing is charged for it.
            try {
                $this->billing->abandon($hold);
            } catch (\Throwable $releaseFailure) {
                report($releaseFailure);
            }

            throw $e;
        }
    }

    /** Everything after the credit hold (if any) was taken; $hold is settled exactly once. */
    private function runHeld(Run $run, string $stepKey, string $prompt, int $timeoutSeconds, ?CreditLedgerEntry $hold): StepOutcome
    {
        $step = RunStep::updateOrCreate(
            ['run_id' => $run->id, 'key' => $stepKey],
            ['status' => RunStepStatus::Running, 'started_at' => now(), 'finished_at' => null, 'error_message' => null]
        );

        $checkoutPath = $this->workspace->path($run);
        $before = $this->workspace->statusPaths($run);

        $transcriptPath = $this->invoker->transcriptPathFor($run->id, $stepKey);
        $credentials = $this->credentials->resolveFor($run, $stepKey);

        // Created BEFORE invoking, not after: confirmed as a real bug via a live user report
        // — a ClaudeInvocation row (and therefore the transcript path the activity endpoint
        // looks for) didn't exist until the whole invocation had already finished, so a
        // multi-minute step showed a bare "Working..." with nothing to tail for its entire
        // duration. HeadlessClaudeInvoker now also streams to the transcript file
        // incrementally rather than writing it once at the end — this row is what lets a
        // concurrent request find that file while it's still being written.
        $invocation = $step->claudeInvocations()->create([
            'command' => explode("\n", trim($prompt))[array_key_last(explode("\n", trim($prompt)))],
            'prompt_text' => $prompt,
            'raw_transcript_path' => $transcriptPath,
            'billing_mode' => $credentials->billingMode,
            'credential_source' => $credentials->source,
            'model' => $credentials->model,
        ]);

        try {
            $result = $this->invoker->invoke(
                $checkoutPath,
                $prompt,
                $transcriptPath,
                $timeoutSeconds,
                fn () => $this->cancellation->isRequested($run->id),
                $credentials,
                $stepKey,
            );
        } finally {
            $credentials->cleanup();
        }

        $invocation->update([
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'total_cost_usd' => $result->totalCostUsd,
            'num_turns' => $result->numTurns,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_creation_input_tokens' => $result->cacheCreationInputTokens,
            'cache_read_input_tokens' => $result->cacheReadInputTokens,
            'stop_reason' => $result->stopReason,
            'timed_out' => $result->timedOut,
        ]);

        // Pricing must never break a pipeline: a failure here is reported, not thrown. (When
        // enforced, the hold then stays held until boschifai:release-stale-holds returns it.)
        try {
            $this->billing->finish($run, $stepKey, $invocation, $result, $hold);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($result->cancelled) {
            $this->cancellation->markCancelled($run, $stepKey, 'Cancelled by user.');

            return new StepOutcome($result, collect());
        }

        $artifacts = collect();

        if ($result->succeeded()) {
            $after = $this->workspace->statusPaths($run);
            $newPaths = $this->diffCollector->newPaths($before, $after);
            $classified = $this->diffCollector->classifyAll($newPaths);

            foreach ($classified as $relativePath => $kindValue) {
                $absolutePath = $checkoutPath.'/'.$relativePath;
                if (! File::exists($absolutePath)) {
                    continue;
                }

                $content = File::get($absolutePath);
                $artifact = RunArtifact::makeFromContent($run->id, ArtifactKind::from($kindValue), $relativePath, $content);
                $artifact->save();
                $artifacts->push($artifact);
            }
        }

        $step->update([
            'status' => $result->timedOut
                ? RunStepStatus::TimedOut
                : ($result->succeeded() ? RunStepStatus::Succeeded : RunStepStatus::Failed),
            'finished_at' => now(),
            'error_message' => $result->succeeded() ? null : $result->failureReason(),
        ]);

        return new StepOutcome($result, $artifacts);
    }

    /**
     * The workspace can't cover this step's hold: the step doesn't run and the run waits in
     * awaiting_credits until someone adds credits and resumes it (RunController::resume).
     */
    private function awaitCredits(Run $run, string $stepKey, InsufficientCredits $e): StepOutcome
    {
        $message = $e->getMessage().' Add credits to this workspace, then resume the run.';

        RunStep::updateOrCreate(
            ['run_id' => $run->id, 'key' => $stepKey],
            ['status' => RunStepStatus::Pending, 'finished_at' => null, 'error_message' => $message]
        );
        $run->update(['state' => RunState::AwaitingCredits, 'awaiting_credits_step' => $stepKey, 'error_message' => $message]);

        return StepOutcome::halted($message);
    }
}
