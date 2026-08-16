<?php

namespace App\Http\Controllers\Api;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRunRequest;
use App\Http\Resources\RunListResource;
use App\Http\Resources\RunResource;
use App\Jobs\PushAndOpenPrJob;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunFixFailingTestsJob;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunLocalTestExecutionJob;
use App\Jobs\RunStandaloneActionJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Jobs\RunTestPlanJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use App\Services\Sandbox\RunWorkspaceManager;
use App\Services\TestExecution\LocalTestRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RunController extends Controller
{
    /**
     * Archived runs are excluded by default — they're deliberately "set aside" (see archive()'s
     * own docblock), not deleted, so they shouldn't clutter the primary list a user checks for
     * what's currently active. ?archived=1 shows only archived ones instead, for the rare case
     * of digging one back up.
     */
    public function index(Request $request)
    {
        $showArchived = $request->boolean('archived');

        $runs = Run::with(['repoConfig', 'executionResult', 'ciCheckResult'])
            ->when($showArchived, fn ($query) => $query->whereNotNull('archived_at'))
            ->unless($showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->latest('updated_at')
            ->get();

        return RunListResource::collection($runs);
    }

    public function store(StoreRunRequest $request)
    {
        $repo = RepoConfig::findOrFail($request->validated('repo_config_id'));

        // Every Run against a given repo shares that repo's one persistent checkout (see
        // RepoCheckoutManager) rather than an isolated per-run copy — two Runs in flight at
        // once for the same repo would corrupt each other's generated files/git state. This is
        // the accepted tradeoff for dropping per-run isolation, enforced here rather than with
        // a real queue/lock, which this MVP doesn't need yet. Archived runs are excluded — see
        // archive()'s own docblock for why setting one aside is safe to treat as "not blocking"
        // regardless of its `state`.
        abort_if(
            Run::where('repo_config_id', $repo->id)
                ->whereNull('archived_at')
                ->whereNotIn('state', $this->terminalStateValues())
                ->exists(),
            422,
            "'{$repo->display_name}' already has a run in progress — wait for it to finish (or reject/cancel/archive it) before submitting another against the same repository."
        );

        $validated = $request->validated();
        unset($validated['attachments']); // files, not a Run column — handled separately below

        $run = Run::create([
            'repo_config_id' => $repo->id,
            ...$validated,
            'state' => RunState::Draft,
            'created_by' => $request->user()?->email ?? $request->ip(),
        ]);

        $run->update(['attachment_filenames' => $this->storeAttachments($run, $request)]);

        match ($run->run_type) {
            RunType::BuildSkills, RunType::BuildKnowledgeBase => RunStandaloneActionJob::dispatch($run->id),
            default => RunGapAnalysisJob::dispatch($run->id),
        };

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    /**
     * Stores each upload under a short-random-prefixed version of its own name (collision-proof
     * without obscuring what the file actually is) at storage/app/boschifai-runs/{run_id}/
     * attachments/ — RunGapAnalysisJob copies from here into the checkout right before invoking
     * Claude. Returns [] (not null) when nothing was uploaded, so the column reads as "no
     * attachments" rather than ambiguously "not yet processed."
     *
     * @return string[] stored filenames
     */
    private function storeAttachments(Run $run, StoreRunRequest $request): array
    {
        $filenames = [];

        foreach ($request->file('attachments', []) as $file) {
            $storedName = Str::random(8).'_'.$file->getClientOriginalName();
            $file->storeAs("boschifai-runs/{$run->id}/attachments", $storedName);
            $filenames[] = $storedName;
        }

        return $filenames;
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
            'fix_failing_tests' => RunFixFailingTestsJob::dispatch($run->id),
            'push' => PushAndOpenPrJob::dispatch($run->id),
            'build_skills', 'build_knowledge_base' => RunStandaloneActionJob::dispatch($run->id),
            default => abort(422, "Step '{$step->key}' cannot be retried directly — resubmit as a new run."),
        };

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    /**
     * Re-runs local_execution for a run that already completed it — distinct from retryStep,
     * which only fires for a Failed run. The motivating case: a run's local_execution can
     * legitimately need re-testing even though it already "succeeded" — e.g. the target repo's
     * own application code changed underneath it (a real bug the generated test caught got
     * fixed) — without resubmitting the whole pipeline or losing the run's existing
     * approvals/artifacts from earlier steps. Only valid from local_execution_complete (Gate 2,
     * not yet pushed) — once a run has pushed, its local result is history, not something to
     * silently overwrite.
     */
    public function rerunLocalExecution(Run $run)
    {
        abort_unless($run->state === RunState::LocalExecutionComplete, 422, 'Run is not awaiting Gate 2 with a completed local execution.');

        $run->executionResult()->delete();
        $run->update(['state' => RunState::LocalExecutionRunning]);

        RunLocalTestExecutionJob::dispatch($run->id);

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    /**
     * Asks Claude to fix whatever's currently failing in the generated test file, then re-runs
     * it locally — see RunFixFailingTestsJob/PromptBuilder::fixFailingTests() for why this is
     * scoped to that one file rather than also touching application code. Same gate as
     * rerunLocalExecution: only valid before push, and only when there's something to fix.
     */
    public function fixFailingTests(Run $run)
    {
        abort_unless($run->state === RunState::LocalExecutionComplete, 422, 'Run is not awaiting Gate 2 with a completed local execution.');
        abort_if(($run->executionResult?->failed ?? 0) === 0, 422, 'No failing tests to fix.');

        $run->update(['state' => RunState::GenerationRunning]);

        RunFixFailingTestsJob::dispatch($run->id);

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    /**
     * Serves PHPUnit's `--coverage-html` output (see LocalTestRunner) from inside the run's own
     * checkout — that report lives on the connected repo's filesystem, not in Boschifai's own
     * storage, so it isn't otherwise web-reachable. `{path}` is a catch-all (see routes/api.php's
     * `.*` constraint) so the report's own relative links between pages and its shared CSS/JS
     * assets resolve correctly through this same route, not just the one page a user first opens.
     *
     * Same realpath-based traversal guard as RepoConfigController::browseFiles() — this serves
     * arbitrary files from a real repo checkout, so a path that escapes coverage-report/ (e.g.
     * `../../.env`) must 404, not read whatever it resolves to.
     */
    public function coverageReport(Run $run, string $path, RunWorkspaceManager $workspace)
    {
        $root = realpath($workspace->path($run).'/coverage-report');
        abort_if($root === false, 404, 'No coverage report has been generated for this run.');

        $target = realpath($root.'/'.ltrim($path, '/'));
        abort_if(
            $target === false || ! (str_starts_with($target, $root.'/') || $target === $root),
            404,
            'Path not found.'
        );
        abort_unless(is_file($target), 404, 'Path not found.');

        // A plain in-memory response, not response()->file() — these HTML/CSS/JS report pages
        // are small, and BinaryFileResponse's streaming semantics buy nothing here while making
        // the response harder to introspect (Symfony's BinaryFileResponse::getContent() always
        // returns false by design).
        return response(File::get($target), 200, ['Content-Type' => $this->coverageReportMimeType($target)]);
    }

    private function coverageReportMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'svg' => 'image/svg+xml',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };
    }

    /**
     * Stops a run's currently in-flight step. Deliberately does NOT flip the state to
     * `cancelled` itself — it only stamps `cancel_requested_at`, since the actual job might be
     * executing in the `worker` container while this request runs in `app`, and the two don't
     * share a process/PID namespace to signal across directly. Whatever job is currently
     * running polls this column itself (CancellationChecker) and stops its own process
     * cooperatively — typically within about a second, per HeadlessClaudeInvoker/
     * LocalTestRunner's poll interval — so the frontend keeps polling `activity`/`show` and
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
     * Sets a run aside without losing it — the motivating case: a run is stuck mid-pipeline or
     * awaiting a gate, but the user wants to start a DIFFERENT run against the same repo right
     * now rather than wait for or reject this one. Deliberately distinct from destroy() (nothing
     * is deleted — every artifact/step/approval stays intact and the run stays viewable) and
     * from cancel()/the approval endpoints' reject (neither actually frees the repo's shared
     * checkout for a genuinely non-terminal run, and "rejected"/"cancelled" would misrepresent a
     * run that's simply being set aside, not abandoned).
     *
     * If the run is still actively in flight, also requests cancellation via the exact same
     * cooperative-stop mechanism cancel() uses — archiving must not leave a job free to keep
     * mutating the shared checkout while a different run starts using it, which is exactly the
     * corruption store()'s own guard exists to prevent.
     */
    public function archive(Run $run)
    {
        if ($this->isCancellableState($run->state)) {
            $run->update(['cancel_requested_at' => now()]);
        }

        $run->update(['archived_at' => now()]);

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    /**
     * Blocked only when un-archiving would recreate the exact conflict archiving was meant to
     * avoid: this run's own state is still non-terminal (so it could resume mutating its repo's
     * shared checkout) AND some other non-terminal, non-archived run already occupies that same
     * checkout. A terminal run (the common case — most runs get archived to declutter the list
     * once they're already done, not to free up a repo) is always safe to bring back, since it
     * doesn't touch the checkout anymore regardless.
     */
    public function unarchive(Run $run)
    {
        abort_unless($run->archived_at !== null, 422, 'Run is not archived.');

        if (! $run->state->isTerminal()) {
            $conflicting = Run::where('repo_config_id', $run->repo_config_id)
                ->where('id', '!=', $run->id)
                ->whereNull('archived_at')
                ->whereNotIn('state', $this->terminalStateValues())
                ->exists();

            abort_if(
                $conflicting,
                422,
                "'{$run->repoConfig->display_name}' already has another active run — archive or finish that one first before restoring this one."
            );
        }

        $run->update(['archived_at' => null]);

        return new RunResource($run->fresh(['repoConfig', 'steps', 'artifacts', 'executionResult', 'ciCheckResult']));
    }

    /** @return string[] */
    private function terminalStateValues(): array
    {
        return array_map(
            fn (RunState $s) => $s->value,
            array_filter(RunState::cases(), fn (RunState $s) => $s->isTerminal())
        );
    }

    /**
     * Deletes a Run and its transcripts. DB cascade (`cascadeOnDelete` on every related table's
     * migration) handles steps/invocations/artifacts/approvals/results — this only has to clean
     * up what lives on disk, not in the database. The repo checkout itself is NOT touched here —
     * unlike the old per-run worktree, it's a persistent resource shared by every Run against
     * that repo, not something this one Run owns.
     *
     * Deliberately allowed for any state, including mid-pipeline ones: if a job is still
     * in-flight for this run when its row disappears out from under it, that job will simply
     * fail with a "Run not found" error on its next DB read — an acceptable outcome for an
     * explicit user delete, not something worth blocking on.
     */
    public function destroy(Run $run)
    {
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
    public function activity(Run $run, ClaudeTranscriptParser $transcriptParser, LocalTestRunner $testRunner)
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

        // local_execution has no ClaudeInvocation (it's a plain composer+phpunit process, not
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
                'message' => $lines === [] ? 'Running the generated test locally (composer install + phpunit)...' : null,
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
     * Plain-text tailer for LocalTestRunner's live log (raw composer/phpunit stdout+stderr,
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
            'test_case_generation', 'code_generation', 'fix_failing_tests' => RunState::GenerationRunning,
            'local_execution' => RunState::LocalExecutionRunning,
            'push' => RunState::Pushing,
            'build_skills', 'build_knowledge_base' => RunState::StandaloneRunning,
            default => RunState::Failed,
        };
    }
}
