<?php

namespace App\Jobs;

use App\Enums\RunState;
use App\Enums\RunStepKey;
use App\Enums\RunStepStatus;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\ClaudeRunner\ClaudeTranscriptParser;
use App\Services\ClaudeRunner\PromptBuilder;
use App\Services\Github\BranchAndCommitService;
use App\Services\Github\PullRequestService;
use App\Services\Pipeline\CancellationChecker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Runs only after Gate 2 human approval (plan §7).
 *
 * Two push mechanisms, chosen by whether github_mcp.pat is configured (see that config's own
 * docblock):
 * - Default: entirely deterministic, never inside anything that also runs Claude/boschifai, so
 *   push credentials are never near agentic code — see BranchAndCommitService/PullRequestService.
 * - Opt-in MCP path: the local branch+commit is still prepared and safety-checked exactly the
 *   same deterministic way (createLocalBranchAndCommit — nothing about that changes), but the
 *   actual push + PR creation runs through a constrained Claude/GitHub-MCP invocation instead,
 *   verified against GitHub's own API afterward. See PromptBuilder::pushViaGithubMcp()'s docblock
 *   for why this exists and what compensates for putting push credentials in the agentic path.
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
        RunWorkspaceManager $workspace,
        BranchAndCommitService $branchCommit,
        PullRequestService $prService,
        StepExecutionService $steps,
        PromptBuilder $prompts,
        ClaudeTranscriptParser $transcriptParser,
        CancellationChecker $cancellation,
    ): void {
        $run = Run::findOrFail($this->runId);

        // Only a top-of-job guard, deliberately — once git/GitHub operations actually start
        // below, they run to completion even if a cancel arrives mid-flight. A push is a
        // handful of git commands plus one API call (seconds, not minutes), and interrupting
        // it partway risks leaving a half-committed branch or a PR opened without its final
        // state recorded — worse than letting a short operation finish. This guard still
        // covers the meaningful case: a cancel clicked while the job was queued but not yet
        // picked up by a worker. (The MCP path's own Claude invocation can still be cancelled
        // mid-flight independently — see below.)
        if ($cancellation->isRequested($run->id)) {
            $cancellation->markCancelled($run, RunStepKey::Push->value, 'Cancelled by user before the push started.');

            return;
        }

        $run->update(['state' => RunState::Pushing]);

        // Created up front (same pattern as RunGapAnalysisJob/RunFixFailingTestsJob), not left
        // for StepExecutionService to create once a Claude invocation actually starts — confirmed
        // as a real bug live: a git-level failure in createLocalBranchAndCommit()/
        // createBranchCommitAndPush() (both non-MCP and MCP paths reach this before Claude is
        // ever invoked) left the run Failed with no RunStep row for this key, so the "Retry step"
        // button's route-model-bound `{step}` had nothing to resolve `failed_step_id` to and
        // 404'd on `/steps/null/retry`.
        $step = RunStep::updateOrCreate(
            ['run_id' => $run->id, 'key' => RunStepKey::Push->value],
            ['status' => RunStepStatus::Running, 'started_at' => now(), 'finished_at' => null, 'error_message' => null]
        );

        $checkoutPath = $workspace->path($run);
        $useMcp = (bool) config('boschifai.github_mcp.pat');

        try {
            if ($useMcp) {
                $branchName = $branchCommit->createLocalBranchAndCommit($run, $checkoutPath);
                $pr = $this->pushViaGithubMcp($run, $branchName, $prService, $steps, $prompts, $transcriptParser);

                if ($pr === null) {
                    // Cancelled mid-flight — StepExecutionService/CancellationChecker already
                    // marked the run Cancelled and created its own step row; nothing left to do.
                    return;
                }
            } else {
                $branchName = $branchCommit->createBranchCommitAndPush($run, $checkoutPath);
                $pr = $prService->create($run, $checkoutPath, $branchName);
            }
        } catch (\Throwable $e) {
            $step->update(['status' => RunStepStatus::Failed, 'finished_at' => now(), 'error_message' => $e->getMessage()]);
            $run->update([
                'state' => RunState::Failed,
                'failed_step' => RunStepKey::Push->value,
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        $step->update(['status' => RunStepStatus::Succeeded, 'finished_at' => now()]);

        $run->update([
            'branch_name' => $branchName,
            'pr_number' => $pr['number'],
            'pr_url' => $pr['url'],
            'head_sha' => $pr['head_sha'],
            'state' => RunState::CiPending,
        ]);

        PollCiStatusJob::dispatch($run->id, 0)->delay(now()->addSeconds(config('boschifai.github.ci_poll_interval_seconds')));
    }

    /**
     * @return ?array{number: int, url: string, head_sha: string} null only when cancelled mid-flight
     */
    private function pushViaGithubMcp(
        Run $run,
        string $branchName,
        PullRequestService $prService,
        StepExecutionService $steps,
        PromptBuilder $prompts,
        ClaudeTranscriptParser $transcriptParser,
    ): ?array {
        $repo = $run->repoConfig;
        $prTitle = $prService->buildTitle($run);
        $prBody = $prService->buildBody($run);

        $prompt = $prompts->pushViaGithubMcp(
            $repo->github_owner,
            $repo->name,
            $branchName,
            $run->generated_file_path,
            $repo->base_branch,
            $prTitle,
            $prBody,
        );

        $outcome = $steps->run($run, RunStepKey::Push->value, $prompt, config('boschifai.claude.timeouts.push'));

        if ($outcome->claudeResult->cancelled) {
            return null;
        }

        if (! $outcome->claudeResult->succeeded()) {
            throw new RuntimeException('GitHub MCP push failed: '.$outcome->claudeResult->stderr);
        }

        $pushResult = $transcriptParser->extractPushResult($outcome->claudeResult->rawTranscriptPath);

        if ($pushResult === null) {
            throw new RuntimeException(
                'Could not find a PR_URL/PR_NUMBER line in the push step\'s transcript — the MCP push may not have completed as instructed.'
            );
        }

        // Independently confirms what GitHub actually recorded matches the constrained prompt —
        // see PullRequestService::verifyPushedPr()'s own docblock. Throws (caught above by the
        // caller) if it doesn't; the run still ends up Failed even though something already
        // reached GitHub. Also returns the PR's real head SHA — NOT a local `git rev-parse
        // HEAD` (see that method's own docblock for why those can genuinely differ here).
        $headSha = $prService->verifyPushedPr($run, $pushResult['number'], $run->generated_file_path, $prBody);

        return ['number' => $pushResult['number'], 'url' => $pushResult['url'], 'head_sha' => $headSha];
    }
}
