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
                'title' => $this->buildTitle($run),
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

    /** Public so PromptBuilder::pushViaGithubMcp() can pass Claude the exact same title verbatim. */
    public function buildTitle(Run $run): string
    {
        return 'test: add coverage for run '.$run->id;
    }

    /** Public for direct unit testing — the "never leak requirement text" guarantee is exactly what needs verifying (plan §12). */
    public function buildBody(Run $run): string
    {
        // Deliberately minimal and template-agnostic for MVP: fills in what Boschifai has
        // evidence for, leaves the rest of the target repo's PR template checkboxes for the
        // human reviewer rather than inventing content for sections it has no evidence for
        // (same principle as boschifai-github-conventions' own error-handling rule).
        //
        // Stack-neutral wording, deliberately: confirmed as a real bug live — this used to claim
        // "Adds PHPUnit Feature test coverage... following boschifai-gen-feature-php-laravel
        // conventions" unconditionally, but both requirement-mode (via /boschifai-gen-component's
        // own multi-stack detection) and coverage-mode runs can target any language/framework the
        // connected repo actually uses (observed live: a TypeScript/Vitest repo). PullRequestService
        // has no reliable signal for which stack/skill was actually used, so it makes no claim
        // about either rather than asserting a specific (possibly wrong) one.
        $coverageLines = array_filter([
            "- Testability score at generation time: {$run->testability_score}%",
            $run->test_case_verdict !== null ? "- Test-case validator verdict: {$run->test_case_verdict}" : null,
            "- Generated file: `{$run->generated_file_path}`",
        ]);

        return implode("\n", [
            '## Summary',
            "- Adds automated test coverage generated via Boschifai for `{$run->generated_file_path}`.",
            '',
            '## Coverage',
            ...$coverageLines,
            '',
            '## Test plan',
            "- [x] Ran locally in Boschifai's sandboxed execution step",
            '- [ ] CI passes',
            '',
            "Related: Boschifai run {$run->id} — see the Boschifai report for full detail (testability review, test plan, generated test cases). Not linked here to a public URL by default; the run's requirement text is not pasted into this PR body per this org's confidentiality policy.",
        ]);
    }

    /**
     * Post-hoc safety net for PushAndOpenPrJob's MCP-based push path (see
     * PromptBuilder::pushViaGithubMcp()'s own docblock for why this exists at all): the local
     * commit was already verified as exactly one file by BranchAndCommitService before Claude
     * ever ran, but this independently confirms GitHub's own record of what actually got pushed
     * and opened matches — in case an MCP tool call somehow diverged from the constrained prompt.
     * Uses the same per-repo OAuth token as the rest of this class, NOT the MCP server's PAT —
     * this check runs entirely in the Laravel process, same as everything else here.
     *
     * Throws (via GitHub's REST API, not the MCP server) if the PR touches anything other than
     * $expectedFilePath, or if its body doesn't match $expectedBody exactly. Either failure marks
     * the run Failed even though something was already pushed to GitHub — this can't undo that,
     * only stop CI polling from treating an unverified push as a normal one. See the job for how
     * that's surfaced.
     *
     * Returns the PR's real head SHA — the caller must use this, not a local `git rev-parse
     * HEAD`: the GitHub MCP server has no tool that pushes an existing local git commit as-is,
     * it creates one through GitHub's own API (see PromptBuilder::pushViaGithubMcp()'s own
     * docblock), which necessarily gets a different SHA than whatever exists locally. Confirmed
     * as a real bug live — PollCiStatusJob polls check-runs by SHA, so a locally-sourced one
     * would poll for a commit GitHub never actually saw.
     */
    public function verifyPushedPr(Run $run, int $prNumber, string $expectedFilePath, string $expectedBody): string
    {
        $repo = $run->repoConfig;
        $token = $this->githubAuth->tokenFor($repo);
        $owner = $repo->github_owner;

        $pr = Http::withToken($token)->acceptJson()
            ->get("https://api.github.com/repos/{$owner}/{$repo->name}/pulls/{$prNumber}");

        if ($pr->failed()) {
            throw new RuntimeException("Could not verify pull request #{$prNumber} after push: ".$pr->body());
        }

        if (trim((string) $pr->json('body')) !== trim($expectedBody)) {
            throw new RuntimeException(
                "Pull request #{$prNumber}'s body does not match what was requested — the MCP push may not have used it verbatim."
            );
        }

        $files = Http::withToken($token)->acceptJson()
            ->get("https://api.github.com/repos/{$owner}/{$repo->name}/pulls/{$prNumber}/files");

        if ($files->failed()) {
            throw new RuntimeException("Could not verify pull request #{$prNumber}'s changed files after push: ".$files->body());
        }

        $filenames = collect($files->json())->pluck('filename')->all();

        if ($filenames !== [$expectedFilePath]) {
            throw new RuntimeException(
                'Pull request #'.$prNumber.' touches '.(implode(', ', $filenames) ?: 'no files')
                    ." — expected only {$expectedFilePath}. Treating this push as unsafe."
            );
        }

        return $pr->json('head.sha');
    }
}
