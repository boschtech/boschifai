<?php

namespace Tests\Unit\Services;

use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Services\Github\GithubOAuthService;
use App\Services\Sandbox\RepoCheckoutManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * A GitHub-connected repo needs its OAuth token embedded in the clone/fetch URL — see
 * RepoCheckoutManager::ensureReady()'s own docblock for why the previously-used `-c
 * http.extraheader=...` technique was replaced (confirmed by live testing to not work at all
 * in this environment). A local repo (no connection) must see zero git activity at all —
 * ensureReady() is a no-op for it.
 */
class RepoCheckoutManagerAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $varPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->varPath = sys_get_temp_dir().'/boschifai-checkout-test-'.uniqid();
        config(['boschifai.var_path' => $this->varPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->varPath);
        parent::tearDown();
    }

    public function test_a_local_repo_is_returned_as_is_with_no_git_commands_run(): void
    {
        Process::fake();

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'Backend',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $path = (new RepoCheckoutManager(new GithubOAuthService()))->ensureReady($repo);

        $this->assertSame('/tmp/does-not-matter', $path);
        Process::assertNothingRan();
    }

    public function test_the_token_is_embedded_in_the_clone_url_for_a_github_connected_repo(): void
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

        (new RepoCheckoutManager(new GithubOAuthService()))->ensureReady($repo);

        Process::assertRan(fn ($process) => in_array(
            'https://gho_faketoken@github.com/acme/backend.git', $process->command, true
        ));
    }

    public function test_the_cloned_checkouts_stored_remote_url_has_no_token_in_it(): void
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

        (new RepoCheckoutManager(new GithubOAuthService()))->ensureReady($repo);

        Process::assertRan(fn ($process) => $process->command === [
            'git', 'remote', 'set-url', 'origin', 'https://github.com/acme/backend.git',
        ]);
    }

    /**
     * Regression test for a real leak caught live: a failed clone against an invalid host threw
     * a RuntimeException whose message included the full credentialed URL — verbatim, token and
     * all — since it was built from the raw command array. Both the command echo and git's own
     * stderr (which also echoes the URL it failed against) must be redacted.
     */
    public function test_a_failed_clone_never_leaks_the_token_in_its_exception_message(): void
    {
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'acme', 'access_token' => 'gho_realtokenvalue',
        ]);

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            // Deliberately unroutable — forces a real (fast) git failure without hitting the network.
            'git_remote_path' => 'https://127.0.0.1.invalid/acme/backend.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id,
            'github_owner' => 'acme',
        ]);

        try {
            (new RepoCheckoutManager(new GithubOAuthService()))->ensureReady($repo);
            $this->fail('Expected the clone to fail against an unroutable host.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('gho_realtokenvalue', $e->getMessage());
        }
    }
}
