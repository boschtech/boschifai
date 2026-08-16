<?php

namespace App\Jobs;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Enums\RunType;
use App\Models\Run;
use App\Services\ArtifactCollection\GitStatusDiffCollector;
use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Plan §4 step 2. On success, the run reaches gap_analysis_ready — Gate 1.
 *
 * Coverage mode reuses this same slot to design test cases directly (there's no separate
 * approval-gate reason to keep a "plan" document apart from the test cases themselves the way
 * requirement mode's testability-review-vs-plan split has) and extracts the single
 * `RECOMMENDED_TARGET_FILE:` line PromptBuilder::coverageTestDesign() asks Claude to end with,
 * so `code_generation` has a concrete file to target — the same role a human fills in for
 * requirement mode via StoreRunRequest's `target_file_path`.
 */
class RunTestPlanJob implements ShouldQueue
{
    use Queueable;

    // See RunTestCaseGenerationJob's $timeout comment for why this override is not optional.
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(
        StepExecutionService $steps,
        PromptBuilder $prompts,
        ClaudeTranscriptParser $transcriptParser,
        GitStatusDiffCollector $diffCollector,
        CancellationChecker $cancellation,
    ): void {
        $run = Run::findOrFail($this->runId);

        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::TestPlan->value, 'Cancelled by user before this step started.');

            return;
        }

        $isCoverage = $run->run_type === RunType::Coverage;

        if ($isCoverage) {
            $instructionRelativePath = ".boschifai/coverage_instruction_{$run->id}.md";
            $knowledgeBaseArtifact = $run->artifactOfKind(ArtifactKind::CodebaseKnowledgeBase->value);
            $prompt = $prompts->coverageTestDesign($instructionRelativePath, $knowledgeBaseArtifact?->relative_path ?? '');
        } else {
            $requirementRelativePath = ".boschifai/requirement_{$run->id}.md";
            $prompt = $prompts->testPlan($requirementRelativePath);
        }

        $outcome = $steps->run(
            $run,
            RunStepKey::TestPlan->value,
            $prompt,
            config('boschifai.claude.timeouts.test_plan'),
        );

        if ($outcome->claudeResult->cancelled) {
            return;
        }

        if (! $outcome->claudeResult->succeeded()) {
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::TestPlan->value,
                'error_message' => $outcome->claudeResult->stderr,
            ]);

            return;
        }

        if ($isCoverage) {
            $targetFilePath = $transcriptParser->extractRecommendedTargetFile($outcome->claudeResult->rawTranscriptPath);

            if ($targetFilePath === null) {
                $run->update([
                    'state' => RunState::Failed,
                    'failed_step' => RunStepKey::TestPlan->value,
                    'error_message' => 'No RECOMMENDED_TARGET_FILE: line was found after coverage test-case design.',
                ]);

                return;
            }

            // Confirmed as a real failure mode: when a target already has a test file from an
            // earlier run against the same shared checkout, Claude can recommend that test file
            // back as its own target instead of the application source it covers.
            // /boschifai-gen-component then EDITS the already-untracked test file in place rather
            // than creating a new one, which never looks "new" to the git-status diff two steps
            // later — surfacing as a confusing "no generated file detected" failure at
            // code_generation instead of a clear one here, right where the bad value originated.
            if ($diffCollector->looksLikeGeneratedTest(basename($targetFilePath), $targetFilePath)) {
                $run->update([
                    'state' => RunState::Failed,
                    'failed_step' => RunStepKey::TestPlan->value,
                    'error_message' => "Coverage test design recommended '{$targetFilePath}' as the target, but that looks like a test file itself, not application source — resubmit this run; if it recurs, the coverage instruction may need to name the target file explicitly.",
                ]);

                return;
            }

            $run->update(['target_file_path' => $targetFilePath]);
        }

        $run->update(['state' => RunState::GapAnalysisReady]);
    }
}
