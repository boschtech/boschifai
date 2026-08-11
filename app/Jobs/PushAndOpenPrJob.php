<?php

namespace App\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Models\Run;
use App\Services\Github\BranchAndCommitService;
use App\Services\Github\PullRequestService;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Sandbox\WorktreeManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs only after Gate 2 human approval (plan §7). Deliberately does not run inside anything
 * that also runs Claude/boschifai — push credentials are never near agentic code.
 */
class PushAndOpenPrJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
    }

    public function handle(
        WorktreeManager $worktrees,
        BranchAndCommitService $branchCommit,
        PullRequestService $prService,
        CancellationChecker $cancellation,
    ): void {
        $run = Run::findOrFail($this->runId);

        // Only a top-of-job guard, deliberately — once git/GitHub operations actually start
        // below, they run to completion even if a cancel arrives mid-flight. A push is a
        // handful of git commands plus one API call (seconds, not minutes), and interrupting
        // it partway risks leaving a half-committed branch or a PR opened without its final
        // state recorded — worse than letting a short operation finish. This guard still
        // covers the meaningful case: a cancel clicked while the job was queued but not yet
        // picked up by a worker.
        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::Push->value, 'Cancelled by user before the push started.');

            return;
        }

        $run->update(['state' => RunState::Pushing]);

        $worktreePath = $worktrees->path($run);

        try {
            $branchName = $branchCommit->createBranchCommitAndPush($run, $worktreePath);
            $pr = $prService->create($run, $worktreePath, $branchName);
        } catch (\Throwable $e) {
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::Push->value,
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        $run->update([
            'branch_name' => $branchName,
            'pr_number' => $pr['number'],
            'pr_url' => $pr['url'],
            'head_sha' => $pr['head_sha'],
            'state' => RunState::CiPending,
        ]);

        PollCiStatusJob::dispatch($run->id, 0)->delay(now()->addSeconds(config('boschifai.github.ci_poll_interval_seconds')));
    }
}
