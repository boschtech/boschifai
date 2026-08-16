<?php

namespace Tests\Feature\Jobs;

use App\Enums\RunState;
use App\Jobs\PollCiStatusJob;
use App\Jobs\PushAndOpenPrJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\Github\GithubOAuthService;
use App\Services\Github\PullRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers both of PushAndOpenPrJob's push mechanisms (see its own docblock): the original
 * deterministic path (default — no github_mcp.pat configured) and the opt-in MCP-based path.
 * `github_connection_id` is deliberately left null throughout so RepoCheckoutManager::path()
 * resolves to this test's own controlled temp directory (a "local" repo, per its own docblock)
 * rather than a var_path clone — GithubOAuthService::tokenFor() is stubbed separately so that
 * choice doesn't also block the GitHub-API-calling parts of these tests.
 *
 * MCP-path tests fake every Process call (not just `claude`) via a single closure keyed on each
 * command's own shape — Process::fake() intercepts every call once registered (there's no
 * "passthrough the rest to a real process" for unmatched patterns), so BranchAndCommitService's
 * own real git commands (checkout/add/diff/commit) need explicit canned responses too, most
 * importantly `git diff --cached --name-only`, which assertOnlyExpectedFileStaged() reads
 * literally — an empty default fake here would trip that safety check for the wrong reason.
 */
class PushAndOpenPrJobTest extends TestCase
{
    use RefreshDatabase;

    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoPath = sys_get_temp_dir().'/boschifai-push-job-test-'.uniqid();
        File::ensureDirectoryExists($this->repoPath.'/tests/Feature');

        $this->git(['init', '-b', 'prod']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Boschifai Test']);
        File::put($this->repoPath.'/README.md', "test repo\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-m', 'initial commit']);

        // Real checkout path (via git_remote_path, no github_connection_id), but tokenFor()
        // stubbed to always succeed regardless — see class docblock.
        $this->app->instance(GithubOAuthService::class, new class extends GithubOAuthService
        {
            public function tokenFor(RepoConfig $repo): string
            {
                return 'fake-token';
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repoPath);
        parent::tearDown();
    }

    private function git(array $args): void
    {
        $result = Process::path($this->repoPath)->run(['git', ...$args]);
        if ($result->failed()) {
            $this->fail('git '.implode(' ', $args).' failed: '.$result->errorOutput());
        }
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend', 'github_owner' => 'acme',
            'git_remote_path' => $this->repoPath, 'base_branch' => 'prod',
        ]);

        File::put($this->repoPath.'/tests/Feature/BillingControllerTest.php', "<?php // generated\n");

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'generated_file_path' => 'tests/Feature/BillingControllerTest.php',
            'testability_score' => 90,
            'test_case_verdict' => 'APPROVED',
            'state' => RunState::LocalExecutionComplete,
        ]);
    }

    /**
     * Fakes every Process call: git commands get realistic canned responses so
     * BranchAndCommitService's own real logic (including assertOnlyExpectedFileStaged's literal
     * read of `git diff --cached --name-only`) behaves as it would for real, and the `claude`
     * invocation gets $claudeResult instead of ever actually running the CLI.
     */
    private function fakeProcesses(Run $run, mixed $claudeResult): void
    {
        Process::fake(function ($process) use ($run, $claudeResult) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            if (str_starts_with($command, 'claude ')) {
                return $claudeResult instanceof \Closure ? $claudeResult($process) : $claudeResult;
            }

            if (str_contains($command, 'git diff --cached --name-only')) {
                return Process::result(output: $run->generated_file_path."\n");
            }

            // checkout/add/commit/status — plain success, empty output is fine for all of these.
            return Process::result();
        });
    }

    public function test_the_default_non_mcp_path_is_used_when_no_pat_is_configured(): void
    {
        config(['boschifai.github_mcp.pat' => null]);
        Http::fake(['api.github.com/*' => Http::response(['number' => 7, 'html_url' => 'https://github.com/acme/backend/pull/7'], 201)]);
        Queue::fake([PollCiStatusJob::class]);

        $run = $this->makeRun();

        app()->call([new PushAndOpenPrJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::CiPending, $run->state);
        $this->assertSame(7, $run->pr_number);
        $this->assertSame('https://github.com/acme/backend/pull/7', $run->pr_url);
        $this->assertNotNull($run->branch_name);
        $this->assertNotNull($run->head_sha);
        Queue::assertPushed(PollCiStatusJob::class);

        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains((string) $request->url(), '/repos/acme/backend/pulls'));
    }

    public function test_the_mcp_path_succeeds_when_claude_pushes_and_the_pr_verifies(): void
    {
        config(['boschifai.github_mcp.pat' => 'fake-pat']);
        Queue::fake([PollCiStatusJob::class]);

        $run = $this->makeRun();
        $expectedBody = app(PullRequestService::class)->buildBody($run);

        $this->fakeProcesses($run, Process::result(output: json_encode([
            'type' => 'assistant',
            'message' => ['content' => [[
                'type' => 'text',
                'text' => "Pushed and opened the PR.\nPR_URL: https://github.com/acme/backend/pull/42\nPR_NUMBER: 42",
            ]]],
        ])."\n"));

        Http::fake([
            'api.github.com/repos/acme/backend/pulls/42/files' => Http::response([
                ['filename' => 'tests/Feature/BillingControllerTest.php'],
            ], 200),
            'api.github.com/repos/acme/backend/pulls/42' => Http::response([
                'body' => $expectedBody,
                // The real head SHA comes from GitHub's own PR response, not a local `git
                // rev-parse HEAD` — see PullRequestService::verifyPushedPr()'s own docblock for
                // why those can genuinely differ for an MCP-based push.
                'head' => ['sha' => str_repeat('b', 40)],
            ], 200),
        ]);

        app()->call([new PushAndOpenPrJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::CiPending, $run->state);
        $this->assertSame(42, $run->pr_number);
        $this->assertSame('https://github.com/acme/backend/pull/42', $run->pr_url);
        $this->assertNotNull($run->branch_name);
        $this->assertSame(str_repeat('b', 40), $run->head_sha);
        Queue::assertPushed(PollCiStatusJob::class);

        // The prompt Claude actually received must repeat the exact PHP-computed title/body
        // verbatim, not let Claude invent its own — see PromptBuilder::pushViaGithubMcp()'s own
        // docblock for why. It must also explicitly forbid `git push`/Bash for the push itself —
        // regression coverage for a real bug caught live: without that instruction, Claude
        // reached for `git push` first, which the sandbox correctly blocks as needing approval
        // (no human available in a headless run), and gave up instead of using the MCP tools it
        // actually had available.
        //
        // It must also pass `--allowedTools` pre-approving exactly the mcp__github__* tools the
        // constrained push prompt needs — regression coverage for two further real bugs caught
        // live: (1) `--permission-mode acceptEdits` pre-approves file Edit/Write but NOT MCP tool
        // calls, so `mcp__github__create_branch` stalled on an approval prompt no headless run
        // could ever answer, identical in shape to the git-push-via-Bash bug above; (2) Claude can
        // reach for either `create_or_update_file` or `push_files` for what the prompt describes
        // as writing one file — both must be pre-approved, or whichever one Claude picks stalls
        // exactly the same way.
        Process::assertRan(function ($process) {
            $command = $process->command;

            return is_array($command)
                && ($command[0] ?? null) === 'claude'
                && str_contains($command[2] ?? '', 'tests/Feature/BillingControllerTest.php')
                && str_contains($command[2] ?? '', 'test: add coverage for run')
                && str_contains($command[2] ?? '', 'Do NOT run `git push`')
                && in_array('--allowedTools', $command, true)
                && in_array(
                    'mcp__github__create_branch,mcp__github__create_or_update_file,mcp__github__push_files,mcp__github__create_pull_request',
                    $command,
                    true
                );
        });
    }

    public function test_the_mcp_path_fails_the_run_when_claude_does_not_report_a_pr(): void
    {
        config(['boschifai.github_mcp.pat' => 'fake-pat']);
        Queue::fake([PollCiStatusJob::class]);

        $run = $this->makeRun();

        $this->fakeProcesses($run, Process::result(output: json_encode([
            'type' => 'assistant',
            'message' => ['content' => [['type' => 'text', 'text' => 'I decided not to push anything.']]],
        ])."\n"));

        app()->call([new PushAndOpenPrJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('push', $run->failed_step);
        $this->assertStringContainsString('PR_URL/PR_NUMBER', $run->error_message);
        Queue::assertNotPushed(PollCiStatusJob::class);
    }

    public function test_the_mcp_path_fails_the_run_when_the_pushed_pr_touches_the_wrong_files(): void
    {
        config(['boschifai.github_mcp.pat' => 'fake-pat']);
        Queue::fake([PollCiStatusJob::class]);

        $run = $this->makeRun();
        $expectedBody = app(PullRequestService::class)->buildBody($run);

        $this->fakeProcesses($run, Process::result(output: json_encode([
            'type' => 'assistant',
            'message' => ['content' => [[
                'type' => 'text',
                'text' => "PR_URL: https://github.com/acme/backend/pull/42\nPR_NUMBER: 42",
            ]]],
        ])."\n"));

        // Claude's push somehow touched an extra file — the exact scenario verifyPushedPr()
        // exists to catch.
        Http::fake([
            'api.github.com/repos/acme/backend/pulls/42/files' => Http::response([
                ['filename' => 'tests/Feature/BillingControllerTest.php'],
                ['filename' => '.env'],
            ], 200),
            'api.github.com/repos/acme/backend/pulls/42' => Http::response(['body' => $expectedBody], 200),
        ]);

        app()->call([new PushAndOpenPrJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('push', $run->failed_step);
        $this->assertStringContainsString('unsafe', $run->error_message);
        Queue::assertNotPushed(PollCiStatusJob::class);
    }

    public function test_the_mcp_path_fails_the_run_when_claude_itself_fails(): void
    {
        config(['boschifai.github_mcp.pat' => 'fake-pat']);
        Queue::fake([PollCiStatusJob::class]);

        $run = $this->makeRun();

        $this->fakeProcesses($run, Process::result(errorOutput: 'boom', exitCode: 1));

        app()->call([new PushAndOpenPrJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('push', $run->failed_step);
        $this->assertStringContainsString('boom', $run->error_message);
        Queue::assertNotPushed(PollCiStatusJob::class);
    }

    /**
     * Regression coverage for a real bug caught live: a git-level failure inside
     * createLocalBranchAndCommit()/createBranchCommitAndPush() — before any Claude invocation,
     * so before StepExecutionService ever creates its own RunStep row — left the run Failed with
     * no RunStep row for the 'push' key at all. The "Retry step" button's route-model-bound
     * `{step}` had nothing to resolve `failed_step_id` to, so `/steps/null/retry` 404'd.
     */
    public function test_a_failure_before_any_claude_invocation_still_creates_a_failed_push_step(): void
    {
        config(['boschifai.github_mcp.pat' => null]);
        $run = $this->makeRun();

        // Reproduces the exact failure class assertOnlyExpectedFileStaged() exists to catch —
        // fails inside createBranchCommitAndPush(), well before any Claude/StepExecutionService
        // involvement.
        File::put($this->repoPath.'/.env', "APP_KEY=should-never-be-committed\n");
        $this->git(['add', '.env']);

        app()->call([new PushAndOpenPrJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::Failed, $run->state);
        $this->assertSame('push', $run->failed_step);

        $step = $run->steps()->where('key', 'push')->first();
        $this->assertNotNull($step, 'Expected a RunStep row to exist even though the failure happened before StepExecutionService ran.');
        $this->assertSame('failed', $step->status->value);
    }

    /**
     * Regression coverage for a real bug caught live: the branch name is fully deterministic
     * (derived only from requirement_text), so retrying a failed push recomputed the exact same
     * name every time. `git checkout -b` failed outright against whatever an earlier attempt had
     * already left behind ("a branch named '...' already exists"), permanently wedging the run —
     * no number of retries could ever succeed. `-B` fixes this by resetting the branch fresh from
     * base_branch instead of refusing to touch it.
     */
    public function test_retrying_succeeds_even_when_the_deterministic_branch_name_already_exists_locally(): void
    {
        config(['boschifai.github_mcp.pat' => null]);
        Http::fake(['api.github.com/*' => Http::response(['number' => 7, 'html_url' => 'https://github.com/acme/backend/pull/7'], 201)]);
        Queue::fake([PollCiStatusJob::class]);

        $run = $this->makeRun();

        // Simulate a previous failed attempt having already created (and left behind) this run's
        // own deterministic branch name.
        $branchName = 'test/'.\Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit('x', 40, ''));
        $this->git(['branch', $branchName]);

        app()->call([new PushAndOpenPrJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::CiPending, $run->state);
        $this->assertSame($branchName, $run->branch_name);
    }

    /**
     * Regression coverage for a real bug caught live: unlike the untracked-file scenario above,
     * this reproduces a generated file that's an ALREADY-TRACKED file in the repo's own history
     * (Claude edited an existing test file rather than creating a new one). A prior attempt had
     * already run createLocalBranchAndCommit() successfully and only failed at the push itself —
     * so the branch already carried the correct generated content, committed with this run's own
     * "Boschifai run: {id}" marker. Retrying re-ran createLocalBranchAndCommit() from scratch,
     * whose `checkout -B branchName base_branch` force-reset the branch back to base — which,
     * because the file is tracked (not untracked), ALSO reset the working tree's file content back
     * to base_branch's version, silently discarding Claude's actual generated edit. The retry then
     * had nothing new to stage/commit, so it appeared to "succeed" while quietly pushing the
     * original, unmodified file.
     */
    public function test_retrying_the_push_step_preserves_a_prior_commits_content_for_an_already_tracked_file(): void
    {
        config(['boschifai.github_mcp.pat' => null]);
        Http::fake(['api.github.com/*' => Http::response(['number' => 7, 'html_url' => 'https://github.com/acme/backend/pull/7'], 201)]);
        Queue::fake([PollCiStatusJob::class]);

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend', 'github_owner' => 'acme',
            'git_remote_path' => $this->repoPath, 'base_branch' => 'prod',
        ]);

        File::put($this->repoPath.'/tests/Feature/BillingControllerTest.php', "<?php // original\n");
        $this->git(['add', 'tests/Feature/BillingControllerTest.php']);
        $this->git(['commit', '-m', 'seed existing tracked test file']);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'generated_file_path' => 'tests/Feature/BillingControllerTest.php',
            'testability_score' => 90,
            'test_case_verdict' => 'APPROVED',
            'state' => RunState::LocalExecutionComplete,
        ]);

        // Simulate the prior attempt's already-successful local commit — the branch already has
        // this run's own generated content, committed with its marker.
        $branchName = 'test/'.\Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit('x', 40, ''));
        $this->git(['checkout', '-b', $branchName]);
        File::put($this->repoPath.'/tests/Feature/BillingControllerTest.php', "<?php // generated content\n");
        $this->git(['add', 'tests/Feature/BillingControllerTest.php']);
        $this->git(['commit', '-m', "test: add coverage for x\n\nBoschifai run: {$run->id}"]);

        // Back to base branch — the working tree now matches base_branch's content again,
        // reproducing exactly the state a retried job would find on disk.
        $this->git(['checkout', 'prod']);

        app()->call([new PushAndOpenPrJob($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(RunState::CiPending, $run->state);
        $this->assertSame($branchName, $run->branch_name);

        $this->git(['checkout', $branchName]);
        $this->assertSame(
            "<?php // generated content\n",
            File::get($this->repoPath.'/tests/Feature/BillingControllerTest.php'),
            'Retrying the push step must not silently discard a prior attempt\'s already-committed generated content.'
        );
    }
}
