<?php

namespace App\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Sandbox\WorktreeManager;
use App\Services\TestExecution\DockerTestRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Plan §5 — runs the generated test locally, before Gate 2. */
class RunLocalTestExecutionJob implements ShouldQueue
{
    use Queueable;

    // DockerTestRunner itself waits up to 900s (composer install + phpunit) — see
    // RunTestCaseGenerationJob's $timeout comment for why the worker-level override matters.
    // Must stay above DockerTestRunner's own $timeoutSeconds, same reasoning as
    // RunCodeGenerationJob's $timeout vs. boschifai.claude.timeouts.code_generation.
    public int $timeout = 1000;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(WorktreeManager $worktrees, DockerTestRunner $runner, CancellationChecker $cancellation): void
    {
        $run = Run::findOrFail($this->runId);

        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::LocalExecution->value, 'Cancelled by user before this step started.');

            return;
        }

        $step = RunStep::updateOrCreate(
            ['run_id' => $run->id, 'key' => RunStepKey::LocalExecution->value],
            ['status' => RunStepStatus::Running, 'started_at' => now(), 'finished_at' => null]
        );

        try {
            $result = $runner->run(
                $run,
                $worktrees->path($run),
                $run->generated_file_path,
                fn () => $cancellation->isRequested($run->id),
            );
        } catch (\Throwable $e) {
            $step->update(['status' => RunStepStatus::Failed, 'finished_at' => now(), 'error_message' => $e->getMessage()]);
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::LocalExecution->value,
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        if ($result['cancelled']) {
            $cancellation->markCancelled($run, RunStepKey::LocalExecution->value, 'Cancelled by user.');

            return;
        }

        $run->executionResult()->create($result);
        $step->update(['status' => RunStepStatus::Succeeded, 'finished_at' => now()]);
        $run->update(['state' => RunState::LocalExecutionComplete]);
    }
}
