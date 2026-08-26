<?php

namespace App\Services\Pipeline;

use App\Enums\ArtifactKind;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunArtifact;
use App\Models\RunStep;
use App\Services\ArtifactCollection\GitStatusDiffCollector;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
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
    ) {
    }

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

        $step = RunStep::updateOrCreate(
            ['run_id' => $run->id, 'key' => $stepKey],
            ['status' => RunStepStatus::Running, 'started_at' => now(), 'finished_at' => null, 'error_message' => null]
        );

        $checkoutPath = $this->workspace->path($run);
        $before = $this->workspace->statusPaths($run);

        $transcriptPath = $this->invoker->transcriptPathFor($run->id, $stepKey);

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
        ]);

        $result = $this->invoker->invoke(
            $checkoutPath,
            $prompt,
            $transcriptPath,
            $timeoutSeconds,
            fn () => $this->cancellation->isRequested($run->id),
            $stepKey,
        );

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
            'error_message' => $result->succeeded() ? null : $result->stderr,
        ]);

        return new StepOutcome($result, $artifacts);
    }
}
