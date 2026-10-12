<?php

namespace Tests\Feature\Console;

use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Services\Sandbox\RepoCheckoutManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrateCheckoutsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $var;

    protected function setUp(): void
    {
        parent::setUp();
        $this->var = sys_get_temp_dir().'/boschifai-var-'.uniqid();
        config(['boschifai.var_path' => $this->var]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->var);
        parent::tearDown();
    }

    private function githubRepo(string $name): RepoConfig
    {
        $connection = GithubConnection::firstOrCreate(
            ['workspace_id' => $this->workspace()->id, 'github_user_id' => 1],
            ['github_login' => 'acme', 'access_token' => 'gho_x'],
        );

        return RepoConfig::create([
            'workspace_id' => $this->workspace()->id, 'name' => $name, 'display_name' => "acme/{$name}",
            'git_remote_path' => "https://github.com/acme/{$name}.git", 'base_branch' => 'main', 'github_connection_id' => $connection->id,
        ]);
    }

    public function test_checkouts_live_per_workspace_and_repository_id(): void
    {
        $repo = $this->githubRepo('api');

        $this->assertSame("{$this->var}/repos/{$this->workspace()->id}/{$repo->id}", app(RepoCheckoutManager::class)->path($repo));
    }

    public function test_moves_old_name_based_checkouts_to_the_new_layout(): void
    {
        $repo = $this->githubRepo('api');
        File::ensureDirectoryExists("{$this->var}/repos/api/.git");
        File::put("{$this->var}/repos/api/README.md", 'clone');

        $this->artisan('boschifai:migrate-checkouts')
            ->expectsOutput("Moved acme/api: repos/api → repos/{$this->workspace()->id}/{$repo->id}")
            ->expectsOutput('Moved 1 checkout(s).')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist("{$this->var}/repos/api");
        $this->assertSame('clone', File::get(app(RepoCheckoutManager::class)->path($repo).'/README.md'));
    }

    public function test_leaves_missing_or_already_moved_checkouts_alone(): void
    {
        $moved = $this->githubRepo('api');
        $this->githubRepo('web'); // never checked out
        File::ensureDirectoryExists(app(RepoCheckoutManager::class)->path($moved));
        File::ensureDirectoryExists("{$this->var}/repos/api"); // stale old copy: new one wins

        $this->artisan('boschifai:migrate-checkouts')->expectsOutput('Nothing to move.')->assertSuccessful();

        $this->assertDirectoryExists("{$this->var}/repos/api");
    }
}
