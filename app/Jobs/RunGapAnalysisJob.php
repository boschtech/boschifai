<?php

namespace App\Jobs;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\ArtifactCollection\CodebaseKnowledgeBaseParser;
use App\Services\ArtifactCollection\TestabilityReviewParser;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;

/**
 * First step of the MVP pipeline (plan §4, step 1): prepares the run's repo checkout, writes the
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
        RunWorkspaceManager $workspace,
        StepExecutionService $steps,
        PromptBuilder $prompts,
        TestabilityReviewParser $reviewParser,
        CodebaseKnowledgeBaseParser $knowledgeBaseParser,
        CancellationChecker $cancellation,
    ): void {
        $run = Run::findOrFail($this->runId);

        // Top-of-job guard: catches a "Stop" click while this run was still sitting queued
        // (dispatched but no free worker yet) — skips checkout prep entirely rather than
        // doing that work only to discard it a moment later. StepExecutionService::run() has
        // its own copy of this same check for the narrower window between here and the actual
        // Claude invocation.
        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::GapAnalysis->value, 'Cancelled by user before this run started.');

            return;
        }

        $run->update(['state' => RunState::GapAnalysisRunning]);

        // RunStep created up front (same pattern as RunLocalTestExecutionJob), not left for
        // StepExecutionService to create once the Claude invocation actually starts: confirmed
        // as a real bug via a live run against a locally-connected repo whose bind mount turned
        // out to be read-only. workspace->prepare()'s `boschifai init` write threw an uncaught
        // RuntimeException with no RunStep row yet in existence, which broke two things at
        // once — RunController::activity read the `_running` state + no running RunStep as
        // "queued, no worker yet" (misleading: the job DID run and DID fail), and retryStep's
        // route-model-bound `{step}` had no RunStep to resolve `failed_step_id` to, so the
        // retry button's `/api/runs/{id}/steps/null/retry` 404'd outright.
        $step = RunStep::updateOrCreate(
            ['run_id' => $run->id, 'key' => RunStepKey::GapAnalysis->value],
            ['status' => RunStepStatus::Running, 'started_at' => now(), 'finished_at' => null, 'error_message' => null]
        );

        try {
            $checkoutPath = $workspace->prepare($run);

            $isCoverage = $run->run_type === RunType::Coverage;
            $inputRelativePath = $isCoverage
                ? ".boschifai/coverage_instruction_{$run->id}.md"
                : ".boschifai/requirement_{$run->id}.md";
            File::put($checkoutPath.'/'.$inputRelativePath, $run->requirement_text);

            // Supporting files uploaded from the user's own machine (see RunController::store)
            // — requirement mode only, matching where the "attach files" control lives on the
            // create form. Copied fresh into the checkout on every attempt (not just once) so a
            // retried gap_analysis step still has them even after RepoCheckoutManager's own
            // reset/clean wiped a GitHub-connected repo's working tree.
            $attachmentRelativePaths = $isCoverage ? [] : $this->copyAttachmentsInto($checkoutPath, $run);
        } catch (\Throwable $e) {
            $step->update(['status' => RunStepStatus::Failed, 'finished_at' => now(), 'error_message' => $e->getMessage()]);
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::GapAnalysis->value,
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        $prompt = $isCoverage
            ? $prompts->codebaseAnalysis($inputRelativePath)
            : $prompts->gapAnalysis($inputRelativePath, $attachmentRelativePaths);

        $outcome = $steps->run(
            $run,
            RunStepKey::GapAnalysis->value,
            $prompt,
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

        if ($isCoverage) {
            $knowledgeBase = $outcome->artifactOfKind(ArtifactKind::CodebaseKnowledgeBase->value);
            $score = $knowledgeBase ? $knowledgeBaseParser->extractScore($knowledgeBase->content) : null;
            $recipe = $knowledgeBase ? $knowledgeBaseParser->extractExecutionRecipe($knowledgeBase->content) : null;

            $run->update(['testability_score' => $score, 'execution_recipe' => $recipe]);
        } else {
            $review = $outcome->artifactOfKind(ArtifactKind::TestabilityReview->value);
            $score = $review ? $reviewParser->extractScore($review->content) : null;

            $run->update(['testability_score' => $score]);
        }

        RunTestPlanJob::dispatch($run->id);
    }

    /**
     * Copies whatever was uploaded in RunController::store() from
     * storage/app/boschifai-runs/{run_id}/attachments/ into the checkout's own
     * `.boschifai/attachments/`, so `/boschifai-review` (running with the checkout as its cwd)
     * can actually read them. Silently skips a stored filename whose file is missing rather than
     * failing the whole step — a missing attachment shouldn't block a run over what's genuinely
     * optional extra context.
     *
     * @return string[] relative paths (from the checkout root) of whatever was actually copied
     */
    private function copyAttachmentsInto(string $checkoutPath, Run $run): array
    {
        $storedFilenames = $run->attachment_filenames ?? [];

        if ($storedFilenames === []) {
            return [];
        }

        $sourceDir = storage_path("app/boschifai-runs/{$run->id}/attachments");
        $destRelativeDir = '.boschifai/attachments';
        File::ensureDirectoryExists($checkoutPath.'/'.$destRelativeDir);

        $copiedRelativePaths = [];
        foreach ($storedFilenames as $filename) {
            $source = $sourceDir.'/'.$filename;
            if (! File::exists($source)) {
                continue;
            }

            $relativePath = $destRelativeDir.'/'.$filename;
            File::copy($source, $checkoutPath.'/'.$relativePath);
            $copiedRelativePaths[] = $relativePath;
        }

        return $copiedRelativePaths;
    }
}
