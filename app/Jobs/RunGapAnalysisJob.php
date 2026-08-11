<?php

namespace App\Jobs;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Models\Run;
use App\Services\ArtifactCollection\TestabilityReviewParser;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\WorktreeManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;

/**
 * First step of the MVP pipeline (plan §4, step 1): creates the run's worktree, writes the
 * requirement, and invokes `/boschifai-review`. On success, chains into RunTestPlanJob — both are
 * bundled into a single Gate 1 (no clear decision boundary between "approve the review" and
 * "approve the plan" to justify two separate clicks).
 */
class RunGapAnalysisJob implements ShouldQueue
{
    use Queueable;

    // See RunTestCaseGenerationJob's $timeout comment — the same real bug (Laravel's queue
    // worker kills jobs at 60s by default, independent of any internal Process::timeout())
    // applies to every Claude-invoking job. Set above boschifai.claude.timeouts.gap_analysis.
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(
        WorktreeManager $worktrees,
        StepExecutionService $steps,
        PromptBuilder $prompts,
        TestabilityReviewParser $reviewParser,
        CancellationChecker $cancellation,
    ): void {
        $run = Run::findOrFail($this->runId);

        // Top-of-job guard: catches a "Stop" click while this run was still sitting queued
        // (dispatched but no free worker yet) — skips worktree creation entirely rather than
        // doing that work only to discard it a moment later. StepExecutionService::run() has
        // its own copy of this same check for the narrower window between here and the actual
        // Claude invocation.
        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::GapAnalysis->value, 'Cancelled by user before this run started.');

            return;
        }

        $run->update(['state' => RunState::GapAnalysisRunning]);

        $worktreePath = $worktrees->create($run);

        $requirementRelativePath = ".boschifai/requirement_{$run->id}.md";
        File::put($worktreePath.'/'.$requirementRelativePath, $run->requirement_text);

        $outcome = $steps->run(
            $run,
            RunStepKey::GapAnalysis->value,
            $prompts->gapAnalysis($requirementRelativePath),
            config('boschifai.claude.timeouts.gap_analysis'),
        );

        if ($outcome->claudeResult->cancelled) {
            return; // state already updated by StepExecutionService/CancellationChecker
        }

        if (! $outcome->claudeResult->succeeded()) {
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::GapAnalysis->value,
                'error_message' => $outcome->claudeResult->stderr,
            ]);

            return;
        }

        $review = $outcome->artifactOfKind(ArtifactKind::TestabilityReview->value);
        $score = $review ? $reviewParser->extractScore($review->content) : null;

        $run->update(['testability_score' => $score]);

        RunTestPlanJob::dispatch($run->id);
    }
}
