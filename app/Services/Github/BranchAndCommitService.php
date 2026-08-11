<?php

namespace App\Services\Github;

use App\Exceptions\UnexpectedStagedFilesException;
use App\Models\Run;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Branches, stages, and commits the single generated test file — never `git add -A`/`.` — then
 * pushes via a connected GitHub OAuth account's token. Runs entirely in the Laravel process,
 * never inside the Claude container, so push credentials are never near agentic code (plan §7).
 */
class BranchAndCommitService
{
    public function __construct(private GithubOAuthService $githubAuth)
    {
    }

    public function createBranchCommitAndPush(Run $run, string $worktreePath): string
    {
        $repo = $run->repoConfig;
        $branchName = 'test/'.Str::slug(Str::limit($this->summarize($run->requirement_text), 40, ''));

        $this->git($worktreePath, ['checkout', '-b', $branchName, $repo->base_branch]);
        $this->git($worktreePath, ['add', $run->generated_file_path]);

        $this->assertOnlyExpectedFileStaged($worktreePath, $run->generated_file_path);

        $this->git($worktreePath, [
            'commit', '-m',
            "test: add coverage for {$this->summarize($run->requirement_text)}\n\nBoschifai run: {$run->id}",
            // Never the raw requirement text in the commit message — link-don't-quote, same
            // rationale as boschifai-github-conventions applies to PR bodies (plan §7, §8, POPIA).
        ]);

        $token = $this->githubAuth->tokenFor($repo);

        $this->git($worktreePath, [
            '-c', "http.extraheader=AUTHORIZATION: bearer {$token}",
            'push', '-u', 'origin', $branchName,
        ]);

        return $branchName;
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
            throw new RuntimeException('git '.implode(' ', $args).' failed: '.$process->errorOutput());
        }
    }
}
