<?php

namespace App\Services\Pipeline;

use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Jobs\PushAndOpenPrJob;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunFixFailingTestsJob;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunLocalTestExecutionJob;
use App\Jobs\RunStandaloneActionJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Jobs\RunTestPlanJob;
use App\Models\Run;
use InvalidArgumentException;

/**
 * (Re)starts one pipeline step of an existing run: sets the run's "running" state for that
 * step and queues its job. Shared by "Retry step" and "Resume" (after credits were added).
 */
class StepDispatcher
{
    private const JOBS = [
        'gap_analysis' => RunGapAnalysisJob::class,
        'test_plan' => RunTestPlanJob::class,
        'test_case_generation' => RunTestCaseGenerationJob::class,
        'code_generation' => RunCodeGenerationJob::class,
        'local_execution' => RunLocalTestExecutionJob::class,
        'fix_failing_tests' => RunFixFailingTestsJob::class,
        'push' => PushAndOpenPrJob::class,
        'build_skills' => RunStandaloneActionJob::class,
        'build_knowledge_base' => RunStandaloneActionJob::class,
    ];

    public function canDispatch(string $stepKey): bool
    {
        return isset(self::JOBS[$stepKey]);
    }

    /** @param  array<string, mixed>  $attributes  further run fields to reset at the same time */
    public function dispatch(Run $run, string $stepKey, array $attributes = []): void
    {
        if (! $this->canDispatch($stepKey)) {
            throw new InvalidArgumentException("Step '{$stepKey}' cannot be started on its own.");
        }

        $run->update(['state' => $this->runningStateFor($stepKey), ...$attributes]);

        self::JOBS[$stepKey]::dispatch($run->id);
    }

    public function runningStateFor(string $stepKey): RunState
    {
        return match ($stepKey) {
            'gap_analysis', 'test_plan' => RunState::GapAnalysisRunning,
            'test_case_generation', 'code_generation', 'fix_failing_tests' => RunState::GenerationRunning,
            'local_execution' => RunState::LocalExecutionRunning,
            'push' => RunState::Pushing,
            'build_skills', 'build_knowledge_base' => RunState::StandaloneRunning,
        };
    }

    /** Whether starting this step invokes Claude (and so needs credits). */
    public function usesClaude(string $stepKey): bool
    {
        return match ($stepKey) {
            RunStepKey::LocalExecution->value, RunStepKey::CiPoll->value => false,
            // Only the MCP push path runs Claude (see PushAndOpenPrJob/HeadlessClaudeInvoker).
            RunStepKey::Push->value => (bool) (config('boschifai.features.github_mcp_push') && config('boschifai.github_mcp.pat')),
            default => true,
        };
    }
}
