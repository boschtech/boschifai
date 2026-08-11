<?php

namespace Tests\Feature\Services;

use App\Exceptions\UnexpectedStagedFilesException;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\Github\BranchAndCommitService;
use App\Services\Github\GithubOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Plan §7 step 2 / §8: the hard safety control that must never let a scope-creeping `git add`
 * reach GitHub. RAMS is documented to carry `.env`, `auth.json`, and a known hardcoded secret
 * in `phpunit.xml` — this test simulates the exact failure mode the check exists for (some
 * other change already staged in the worktree before Boschifai's own commit step runs) and
 * confirms it's caught, not silently pushed.
 */
class BranchAndCommitServiceStagedDiffTest extends TestCase
{
    use RefreshDatabase;

    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoPath = sys_get_temp_dir().'/boschifai-test-repo-'.uniqid();
        File::ensureDirectoryExists($this->repoPath);

        $this->git(['init', '-b', 'prod']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Boschifai Test']);
        File::put($this->repoPath.'/README.md', "test repo\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-m', 'initial commit']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repoPath);
        parent::tearDown();
    }

    public function test_throws_if_an_unrelated_file_is_already_staged_before_the_generated_file_is_added(): void
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'Backend',
            'git_remote_path' => $this->repoPath, 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => 'local_execution_complete',
            'generated_file_path' => 'tests/Feature/CustomFieldControllerTest.php',
        ]);

        File::ensureDirectoryExists($this->repoPath.'/tests/Feature');
        File::put($this->repoPath.'/tests/Feature/CustomFieldControllerTest.php', "<?php // generated\n");

        // Simulate the exact failure mode: something else (a stray .env edit, an IDE artifact)
        // is already staged in this worktree before Boschifai's own add/commit step runs.
        File::put($this->repoPath.'/.env', "APP_KEY=should-never-be-committed\n");
        $this->git(['add', '.env']);

        $this->expectException(UnexpectedStagedFilesException::class);

        (new BranchAndCommitService(new GithubOAuthService()))->createBranchCommitAndPush($run, $this->repoPath);
    }

    public function test_commits_successfully_when_only_the_expected_file_is_staged(): void
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'Backend',
            'git_remote_path' => $this->repoPath, 'base_branch' => 'prod',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
            'state' => 'local_execution_complete',
            'generated_file_path' => 'tests/Feature/CustomFieldControllerTest.php',
        ]);

        File::ensureDirectoryExists($this->repoPath.'/tests/Feature');
        File::put($this->repoPath.'/tests/Feature/CustomFieldControllerTest.php', "<?php // generated\n");

        // No GitHub connection linked — expect the failure to come from the (absent) push step,
        // proving the staged-diff assertion and commit themselves succeeded first. Using
        // try/catch rather than expectException() so the commit can still be inspected after.
        try {
            (new BranchAndCommitService(new GithubOAuthService()))->createBranchCommitAndPush($run, $this->repoPath);
            $this->fail('Expected a RuntimeException for the missing GitHub connection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has no GitHub account connected', $e->getMessage());
        }

        $log = Process::path($this->repoPath)->run(['git', 'log', '--oneline', '-1'])->output();
        $this->assertStringContainsString('test: add coverage for', $log);
    }

    private function git(array $args): void
    {
        $result = Process::path($this->repoPath)->run(['git', ...$args]);
        if ($result->failed()) {
            $this->fail('git '.implode(' ', $args).' failed: '.$result->errorOutput());
        }
    }
}
