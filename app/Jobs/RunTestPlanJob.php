<?php

namespace App\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Models\Run;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Plan §4 step 2. On success, the run reaches gap_analysis_ready — Gate 1. */
class RunTestPlanJob implements ShouldQueue
{
    use Queueable;

    // See RunTestCaseGenerationJob's $timeout comment for why this override is not optional.
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(StepExecutionService $steps, PromptBuilder $prompts, CancellationChecker $cancellation): void
    {
        $run = Run::findOrFail($this->runId);

        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::TestPlan->value, 'Cancelled by user before this step started.');

            return;
        }

        $requirementRelativePath = ".boschifai/requirement_{$run->id}.md";

        $outcome = $steps->run(
            $run,
            RunStepKey::TestPlan->value,
            $prompts->testPlan($requirementRelativePath),
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

        $run->update(['state' => RunState::GapAnalysisReady]);
    }
}
