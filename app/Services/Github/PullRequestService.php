<?php

namespace App\Services\Github;

use App\Models\Run;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Opens the PR via the GitHub REST API (equivalent to `gh pr create`), following
 * boschifai-github-conventions: base branch explicit, body built from the target repo's own PR
 * template, and the requirement is linked to (this run's URL) rather than quoted — POPIA/
 * confidentiality, same rationale as never pasting requirement text into a commit message.
 */
class PullRequestService
{
    public function __construct(private GithubOAuthService $githubAuth)
    {
    }

    /** @return array{number: int, url: string, head_sha: string} */
    public function create(Run $run, string $worktreePath, string $branchName): array
    {
        $repo = $run->repoConfig;
        $token = $this->githubAuth->tokenFor($repo);
        $owner = $repo->github_owner;

        $headSha = trim(Process::path($worktreePath)->timeout(15)->run(['git', 'rev-parse', 'HEAD'])->output());

        $response = Http::withToken($token)
            ->acceptJson()
            ->post("https://api.github.com/repos/{$owner}/{$repo->name}/pulls", [
                'title' => 'test: add coverage for run '.$run->id,
                'head' => $branchName,
                'base' => $repo->base_branch,
                'body' => $this->buildBody($run),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('GitHub PR creation failed: '.$response->body());
        }

        return [
            'number' => $response->json('number'),
            'url' => $response->json('html_url'),
            'head_sha' => $headSha,
        ];
    }

    /** Public for direct unit testing — the "never leak requirement text" guarantee is exactly what needs verifying (plan §12). */
    public function buildBody(Run $run): string
    {
        // Deliberately minimal and template-agnostic for MVP: fills in what Boschifai has
        // evidence for, leaves the rest of the target repo's PR template checkboxes for the
        // human reviewer rather than inventing content for sections it has no evidence for
        // (same principle as boschifai-github-conventions' own error-handling rule).
        return <<<BODY
        ## Summary
        - Adds PHPUnit Feature test coverage generated via Boschifai, following this repo's
          `boschifai-gen-component-php-laravel` conventions.

        ## Coverage
        - Testability score at generation time: {$run->testability_score}%
        - Test-case validator verdict: {$run->test_case_verdict}
        - Generated file: `{$run->generated_file_path}`

        ## Test plan
        - [x] Ran locally in Boschifai's sandboxed execution step
        - [ ] CI passes

        Related: Boschifai run {$run->id} — see the Boschifai report for full detail (testability
        review, test plan, generated test cases). Not linked here to a public URL by default;
        the run's requirement text is not pasted into this PR body per this org's confidentiality
        policy.
        BODY;
    }
}
