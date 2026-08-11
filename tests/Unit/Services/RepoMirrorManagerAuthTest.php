<?php

namespace Tests\Unit\Services;

use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Services\Github\GithubOAuthService;
use App\Services\Sandbox\RepoMirrorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * A private, GitHub-connected repo needs the same `-c http.extraheader=...` technique
 * BranchAndCommitService already uses for push, injected into the clone/fetch commands too —
 * without it, ensureFresh() silently fails to auth against a private HTTPS remote. The
 * pre-existing local-path "rams" row (no connection) must see zero behavior change.
 */
class RepoMirrorManagerAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $varPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->varPath = sys_get_temp_dir().'/boschifai-mirror-test-'.uniqid();
        config(['boschifai.var_path' => $this->varPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->varPath);
        parent::tearDown();
    }

    public function test_no_auth_header_is_injected_for_a_repo_with_no_github_connection(): void
    {
        Process::fake();

        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        (new RepoMirrorManager(new GithubOAuthService()))->ensureFresh($repo);

        Process::assertRan(function ($process) {
            return in_array('git', $process->command, true) && ! in_array('-c', $process->command, true);
        });
    }

    public function test_auth_header_is_injected_for_a_github_connected_repo(): void
    {
        Process::fake();

        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'acme', 'access_token' => 'gho_faketoken',
        ]);

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id,
            'github_owner' => 'acme',
        ]);

        (new RepoMirrorManager(new GithubOAuthService()))->ensureFresh($repo);

        Process::assertRan(function ($process) {
            return in_array('-c', $process->command, true)
                && in_array('http.extraheader=AUTHORIZATION: bearer gho_faketoken', $process->command, true);
        });
    }
}
