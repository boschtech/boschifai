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
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the two "Standalone Actions" (SidebarNav): build_skills / build_knowledge_base. Unlike
 * every other Run type, this is a single Claude invocation with no approval gate and no push —
 * Claude reads the connected repo and writes one artifact, viewable directly in the Boschifai UI.
 * Modeled as a RunType/Run row (not a separate table) purely to get the existing checkout
 * management (RunWorkspaceManager), artifact-diffing (StepExecutionService/
 * GitStatusDiffCollector), and Runs list/history pages for free — there is deliberately no
 * chaining into RunTestPlanJob or any other pipeline step afterward.
 */
class RunStandaloneActionJob implements ShouldQueue
{
    use Queueable;

    // See RunGapAnalysisJob's identical $timeout comment — Laravel's queue worker kills a job at
    // 60s by default regardless of any internal Process::timeout(). Set above
    // boschifai.claude.timeouts.gap_analysis, whose tier this reuses (same shape of work: explore
    // a repo, write one document).
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(
        RunWorkspaceManager $workspace,
        StepExecutionService $steps,
        PromptBuilder $prompts,
        CodebaseKnowledgeBaseParser $knowledgeBaseParser,
        CancellationChecker $cancellation,
    ): void {
        $run = Run::findOrFail($this->runId);
        $stepKey = $this->stepKeyFor($run);

        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, $stepKey, 'Cancelled by user before this run started.');

            return;
        }

        $run->update(['state' => RunState::StandaloneRunning]);

        // Created up front, not left for StepExecutionService to create once Claude actually
        // starts — same fix as RunGapAnalysisJob/PushAndOpenPrJob's identical bug, confirmed live
        // earlier this session: a checkout-prep failure below (workspace->prepare()) would
        // otherwise leave the run Failed with no RunStep row for this key at all, and the
        // "Retry step" button's route-model-bound `{step}` would have nothing to resolve
        // `failed_step_id` to, 404ing on `/steps/null/retry`.
        $step = RunStep::updateOrCreate(
            ['run_id' => $run->id, 'key' => $stepKey],
            ['status' => RunStepStatus::Running, 'started_at' => now(), 'finished_at' => null, 'error_message' => null]
        );

        try {
            $workspace->prepare($run);
        } catch (\Throwable $e) {
            $step->update(['status' => RunStepStatus::Failed, 'finished_at' => now(), 'error_message' => $e->getMessage()]);
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => $stepKey,
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        $isBuildSkills = $run->run_type === RunType::BuildSkills;
        $prompt = $isBuildSkills
            ? $prompts->standaloneProjectSkill($run->id)
            : $prompts->standaloneKnowledgeBase($run->id);

        $outcome = $steps->run($run, $stepKey, $prompt, config('boschifai.claude.timeouts.gap_analysis'));

        if ($outcome->claudeResult->cancelled) {
            return; // state already updated by StepExecutionService/CancellationChecker
        }

        if (! $outcome->claudeResult->succeeded()) {
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => $stepKey,
                'error_message' => $outcome->claudeResult->stderr,
            ]);

            return;
        }

        $artifactKind = $isBuildSkills ? ArtifactKind::ProjectSkill : ArtifactKind::CodebaseKnowledgeBase;
        $artifact = $outcome->artifactOfKind($artifactKind->value);
        $score = $artifact ? $knowledgeBaseParser->extractScore($artifact->content) : null;

        $run->update(['testability_score' => $score, 'state' => RunState::StandaloneComplete]);
    }

    private function stepKeyFor(Run $run): string
    {
        return $run->run_type === RunType::BuildSkills
            ? RunStepKey::BuildSkills->value
            : RunStepKey::BuildKnowledgeBase->value;
    }
}
