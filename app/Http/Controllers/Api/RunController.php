<?php

namespace App\Http\Controllers\Api;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRunRequest;
use App\Http\Resources\RunListResource;
use App\Http\Resources\RunResource;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunLocalTestExecutionJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Jobs\RunTestPlanJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use App\Services\Sandbox\WorktreeManager;
use App\Services\TestExecution\DockerTestRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class RunController extends Controller
{
    public function index()
    {
        $runs = Run::with('repoConfig')->latest('updated_at')->get();

        return RunListResource::collection($runs);
    }

    public function store(StoreRunRequest $request)
    {
        $repo = RepoConfig::findOrFail($request->validated('repo_config_id'));

        // Defense-in-depth under the frontend's disabled-submit state (see RunCreatePage.vue)
        // — a repo connected via "Connect GitHub" but never given a test-runner image would
        // otherwise fail confusingly deep inside the pipeline instead of at submission time.
        abort_if(
            blank($repo->docker_image),
            422,
            "Repository '{$repo->display_name}' has no test-runner Docker image configured — connect one before submitting requirements against it."
        );

        $run = Run::create([
            'repo_config_id' => $repo->id,
            ...$request->validated(),
            'state' => RunState::Draft,
            'created_by' => $request->user()?->email ?? $request->ip(),
        ]);

        RunGapAnalysisJob::dispatch($run->id);

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    public function show(Run $run)
    {
        return new RunResource(
            $run->load(['repoConfig', 'steps.claudeInvocations', 'artifacts', 'executionResult', 'ciCheckResult', 'approvals'])
        );
    }

    /**
     * Re-invokes the step that failed, without touching artifacts already produced by earlier
     * steps — an unattended retry on a paid, non-deterministic call should be a human's choice
     * (plan §3), this endpoint is that choice.
     */
    public function retryStep(Run $run, RunStep $step)
    {
        abort_if($step->run_id !== $run->id, 404);
        abort_unless($run->state === RunState::Failed, 422, 'Run is not in a failed state.');

        $run->update(['state' => $this->runningStateFor($step->key), 'error_message' => null, 'failed_step' => null]);

        match ($step->key) {
            'gap_analysis' => RunGapAnalysisJob::dispatch($run->id),
            'test_plan' => RunTestPlanJob::dispatch($run->id),
            'test_case_generation' => RunTestCaseGenerationJob::dispatch($run->id),
            'code_generation' => RunCodeGenerationJob::dispatch($run->id),
            'local_execution' => RunLocalTestExecutionJob::dispatch($run->id),
            default => abort(422, "Step '{$step->key}' cannot be retried directly — resubmit as a new run."),
        };

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    /**
     * Stops a run's currently in-flight step. Deliberately does NOT flip the state to
     * `cancelled` itself — it only stamps `cancel_requested_at`, since the actual job might be
     * executing in the `worker` container while this request runs in `app`, and the two don't
     * share a process/PID namespace to signal across directly. Whatever job is currently
     * running polls this column itself (CancellationChecker) and stops its own process
     * cooperatively — typically within about a second, per HeadlessClaudeInvoker/
     * DockerTestRunner's poll interval — so the frontend keeps polling `activity`/`show` and
     * picks up the `cancelled` state once the job notices.
     */
    public function cancel(Run $run)
    {
        abort_unless($this->isCancellableState($run->state), 422, 'Run is not in a state that can be stopped.');

        $run->update(['cancel_requested_at' => now()]);

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    private function isCancellableState(RunState $state): bool
    {
        return in_array($state, [
            RunState::Draft,
            RunState::GapAnalysisRunning,
            RunState::GenerationRunning,
            RunState::LocalExecutionRunning,
            RunState::Pushing,
            RunState::CiPending,
        ], true);
    }

    /**
     * Deletes a Run and its worktree/transcripts. DB cascade (`cascadeOnDelete` on every
     * related table's migration) handles steps/invocations/artifacts/approvals/results — this
     * only has to clean up what lives on disk, not in the database.
     *
     * Deliberately allowed for any state, including mid-pipeline ones: if a job is still
     * in-flight for this run when its worktree disappears out from under it, that job will
     * simply fail with a "Run not found" error on its next DB read — an acceptable outcome for
     * an explicit user delete, not something worth blocking on.
     */
    public function destroy(Run $run, WorktreeManager $worktrees)
    {
        $run->load('repoConfig');

        try {
            $worktrees->remove($run);
        } catch (\Throwable $e) {
            // Best-effort — a worktree that's already gone, mid-use, or was never created
            // (e.g. a run still in `draft`) shouldn't block deleting the run record itself.
        }

        File::deleteDirectory(storage_path("app/boschifai-runs/{$run->id}"));

        $run->delete();

        return response()->noContent();
    }

    /**
     * Live progress for the run detail page — added directly in response to a real user report
     * ("I'm not sure if anything is happening") caused by two compounding gaps: a queued job
     * with no worker yet to pick it up gives zero feedback, and even a genuinely-running Claude
     * invocation was previously just a static timeline badge with no visibility into what it
     * was actually doing.
     */
    public function activity(Run $run, ClaudeTranscriptParser $transcriptParser, DockerTestRunner $testRunner)
    {
        $run->load('steps.claudeInvocations');
        $runningStep = $run->steps->first(fn (RunStep $s) => $s->status === RunStepStatus::Running);

        if ($runningStep === null) {
            $expectingWork = str_ends_with($run->state->value, '_running')
                || in_array($run->state, [RunState::Draft, RunState::Pushing, RunState::CiPending], true);

            return response()->json([
                'phase' => $expectingWork ? 'queued' : 'idle',
                'step_key' => null,
                'elapsed_seconds' => $expectingWork ? $this->elapsedSeconds($run->updated_at) : null,
                'cost_so_far_usd' => null,
                'log_lines' => [],
                'message' => $expectingWork
                    ? 'Queued, but no worker has picked this up yet. Confirm a queue worker is running (`php artisan queue:work`, or the Docker `worker` container — check `docker compose ps`).'
                    : null,
            ]);
        }

        // local_execution has no ClaudeInvocation (it's a `docker run` of composer+phpunit, not
        // a Claude call) — tail its own live output log instead, same "don't just sit on a
        // static badge for minutes" motivation as the Claude-step case below.
        if ($runningStep->key === 'local_execution') {
            $lines = $this->tailPlainTextLog($testRunner->logPathFor($run->id));

            return response()->json([
                'phase' => 'running',
                'step_key' => $runningStep->key,
                'elapsed_seconds' => $this->elapsedSeconds($runningStep->started_at),
                'cost_so_far_usd' => null,
                'log_lines' => $lines,
                'message' => $lines === [] ? 'Running the generated test locally via Docker (composer install + phpunit)...' : null,
            ]);
        }

        $latestInvocation = $runningStep->claudeInvocations->last();

        if ($latestInvocation === null) {
            return response()->json([
                'phase' => 'running',
                'step_key' => $runningStep->key,
                'elapsed_seconds' => $this->elapsedSeconds($runningStep->started_at),
                'cost_so_far_usd' => null,
                'log_lines' => [],
                'message' => match ($runningStep->key) {
                    'push' => 'Committing and opening the pull request...',
                    'ci_poll' => 'Waiting on CI to conclude...',
                    default => 'Working...',
                },
            ]);
        }

        return response()->json([
            'phase' => 'claude_running',
            'step_key' => $runningStep->key,
            'elapsed_seconds' => $this->elapsedSeconds($runningStep->started_at),
            'cost_so_far_usd' => $latestInvocation->total_cost_usd,
            'log_lines' => $transcriptParser->tailEvents($latestInvocation->raw_transcript_path),
            'message' => null,
        ]);
    }

    /**
     * Plain-text tailer for DockerTestRunner's live log (raw composer/phpunit stdout+stderr,
     * not Claude's JSONL stream — ClaudeTranscriptParser's tailEvents() doesn't apply here).
     * Returns at most one element so the frontend's existing `log_lines.join('\n\n')` renders
     * it as one continuous block with real single-newlines preserved, exactly like a terminal.
     *
     * @return string[]
     */
    private function tailPlainTextLog(string $path, int $maxLines = 300): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $lines = array_values(array_filter(
            explode("\n", File::get($path)),
            fn (string $line) => trim($line) !== ''
        ));

        $tail = array_slice($lines, -$maxLines);

        return $tail === [] ? [] : [implode("\n", $tail)];
    }

    /**
     * Carbon 3 (Laravel 11) returns a *signed*, sub-second-precision diff by default now (a
     * breaking change from Carbon 2, which defaulted to an absolute whole-second value) —
     * without both `absolute: true` and rounding, this showed as e.g. "4m 53.70599599999997s"
     * in the UI, caught by a real screenshot from the user.
     */
    private function elapsedSeconds(\DateTimeInterface $since): int
    {
        return (int) round(now()->diffInSeconds($since, absolute: true));
    }

    private function runningStateFor(string $stepKey): RunState
    {
        return match ($stepKey) {
            'gap_analysis', 'test_plan' => RunState::GapAnalysisRunning,
            'test_case_generation', 'code_generation' => RunState::GenerationRunning,
            'local_execution' => RunState::LocalExecutionRunning,
            default => RunState::Failed,
        };
    }
}
