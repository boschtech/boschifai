<?php

namespace App\Services\Github;

use App\Exceptions\UnexpectedStagedFilesException;
use App\Models\Run;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Branches, stages, and commits the single generated test file — never `git add -A`/`.` — then
 * (by default) pushes via a connected GitHub OAuth account's token. Runs entirely in the Laravel
 * process, never inside the Claude container, so push credentials are never near agentic code
 * (plan §7) — createLocalBranchAndCommit()/pushBranch() split below exists specifically for
 * PushAndOpenPrJob's opt-in MCP-based push path, which deliberately does put a (different, PAT-
 * based) credential in the same process as agentic code; see that job for the compensating
 * controls. The local commit itself — and this class's own staged-file safety check — happens
 * identically either way, entirely before Claude is ever invoked.
 */
class BranchAndCommitService
{
    public function __construct(private GithubOAuthService $githubAuth)
    {
    }

    public function createBranchCommitAndPush(Run $run, string $worktreePath): string
    {
        $branchName = $this->createLocalBranchAndCommit($run, $worktreePath);
        $this->pushBranch($run, $worktreePath, $branchName);

        return $branchName;
    }

    /** Everything up to and including the local commit — no push. */
    public function createLocalBranchAndCommit(Run $run, string $worktreePath): string
    {
        $repo = $run->repoConfig;
        $branchName = 'test/'.Str::slug(Str::limit($this->summarize($run->requirement_text), 40, ''));

        // Confirmed as a real bug live: a retried push (e.g. after the push step itself failed,
        // not this local-commit step) re-ran this whole method from scratch, including
        // `checkout -B` resetting the branch back to $repo->base_branch. For an UNTRACKED
        // generated file that's harmless — its content survives independently of git ref state.
        // But when the target already had a real, TRACKED test file in the repo's own history
        // (Claude edited an existing file rather than creating a new one), the edit's only
        // record was the commit itself; resetting the branch discarded that commit, which
        // silently reverted the working tree's file back to $repo->base_branch's committed
        // content — the actual generated edit was gone, and re-running add/commit just
        // re-committed the (unmodified) original. If this exact run already has a correct commit
        // sitting on this branch (checked via the commit message's own marker below), reuse it
        // instead of ever re-deriving it from a working tree that may no longer hold it.
        if ($this->branchAlreadyHasThisRunsCommit($worktreePath, $branchName, $run)) {
            $this->git($worktreePath, ['checkout', $branchName]);

            return $branchName;
        }

        // `-B`, not `-b`: the branch name is fully deterministic (derived only from
        // requirement_text), so a retried push step always recomputes the exact same name.
        // `-b` fails outright ("a branch named '...' already exists") against whatever a
        // previous attempt left behind — confirmed as a real bug live. `-B` creates it fresh if
        // it doesn't exist, or resets it to start clean from $repo->base_branch if it does. Safe
        // here specifically because the check above already ruled out "this exact run's own
        // correct commit is sitting on this branch" — anything else left behind (a different
        // run's stale attempt, or nothing at all) is fine to discard/recreate.
        $this->git($worktreePath, ['checkout', '-B', $branchName, $repo->base_branch]);
        $this->git($worktreePath, ['add', $run->generated_file_path]);

        $this->assertOnlyExpectedFileStaged($worktreePath, $run->generated_file_path);

        $this->git($worktreePath, [
            'commit', '-m',
            "test: add coverage for {$this->summarize($run->requirement_text)}\n\nBoschifai run: {$run->id}",
            // Never the raw requirement text in the commit message — link-don't-quote, same
            // rationale as boschifai-github-conventions applies to PR bodies (plan §7, §8, POPIA).
        ]);

        return $branchName;
    }

    private function branchAlreadyHasThisRunsCommit(string $worktreePath, string $branchName, Run $run): bool
    {
        $branchExists = Process::path($worktreePath)->timeout(15)
            ->run(['git', 'rev-parse', '--verify', '--quiet', $branchName])
            ->successful();

        if (! $branchExists) {
            return false;
        }

        $tipMessage = Process::path($worktreePath)->timeout(15)
            ->run(['git', 'log', '-1', '--format=%B', $branchName])
            ->output();

        return str_contains($tipMessage, "Boschifai run: {$run->id}");
    }

    /**
     * Pushes an already-committed local branch via a connected GitHub OAuth account's token —
     * the original, non-MCP path. Kept as its own method (rather than folded back into
     * createBranchCommitAndPush()) so nothing about the credentialed-push mechanics needs
     * touching for that method to keep working unchanged.
     */
    public function pushBranch(Run $run, string $worktreePath, string $branchName): void
    {
        $repo = $run->repoConfig;

        // See RepoCheckoutManager::ensureReady()'s docblock — the `-c http.extraheader=...`
        // technique this used to use is confirmed (by live testing) to not work in this
        // environment at all. A credentialed URL does. No `-u`/named remote here, deliberately:
        // that would persist the token into this checkout's `.git/config` (either directly, or
        // via a temporary `remote set-url` that's still a real window of on-disk exposure).
        // Pushing straight to an explicit URL never touches config at all, and nothing downstream
        // (PullRequestService) needs git's own upstream-tracking metadata — it opens the PR via
        // the REST API using the branch name string returned here.
        $token = $this->githubAuth->tokenFor($repo);
        $credentialedUrl = preg_replace('#^https://#', "https://{$token}@", $repo->git_remote_path);

        $this->git($worktreePath, ['push', $credentialedUrl, "{$branchName}:{$branchName}"]);
    }

    /**
     * Hard safety control (plan §7 step 2, §8): the staged diff must contain exactly the
     * expected generated file and nothing else. RAMS carries `.env`, `auth.json`, and a known
     * hardcoded secret in `phpunit.xml` — a scope-creeping `git add` must never reach GitHub.
     */
    private function assertOnlyExpectedFileStaged(string $worktreePath, string $expectedPath): void
    {
        $result = Process::path($worktreePath)->timeout(30)->run(['git', 'diff', '--cached', '--name-only']);
        $staged = array_filter(explode("\n", trim($result->output())));

        $unexpected = array_values(array_diff($staged, [$expectedPath]));

        if ($staged !== [$expectedPath] || ! empty($unexpected)) {
            throw new UnexpectedStagedFilesException($unexpected ?: $staged);
        }
    }

    private function summarize(string $requirementText): string
    {
        $firstLine = trim(explode("\n", trim($requirementText))[0] ?? 'requirement');

        return Str::limit(ltrim($firstLine, "# \t"), 60, '');
    }

    private function git(string $cwd, array $args): void
    {
        $process = Process::path($cwd)->timeout(60)->run(['git', ...$args]);

        if ($process->failed()) {
            throw new RuntimeException('git '.implode(' ', $this->redact($args)).' failed: '.$this->redact([$process->errorOutput()])[0]);
        }
    }

    /**
     * The OAuth token embedded in the push URL must never land in an exception message/log —
     * those get surfaced to the run's error_message column and this class's own callers, neither
     * of which should ever hold a live credential. Git's own error output can also echo the URL
     * it failed against, so that needs redacting too.
     */
    private function redact(array $values): array
    {
        return array_map(
            fn (string $value) => preg_replace('#https://[^/@\s]+@#', 'https://[redacted]@', $value),
            $values
        );
    }
}
