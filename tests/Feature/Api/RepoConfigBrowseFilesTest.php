<?php

namespace Tests\Feature\Api;

use App\Models\GithubConnection;
use App\Models\RepoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Backs the run-create form's "Browse files…" picker — lists a connected repo's OWN checkout
 * (not the local-repos root LocalRepoController browses to connect a repo in the first place).
 */
class RepoConfigBrowseFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $checkoutPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkoutPath = sys_get_temp_dir().'/boschifai-browse-files-test-'.uniqid();
        File::ensureDirectoryExists($this->checkoutPath.'/app/Http/Controllers');
        File::ensureDirectoryExists($this->checkoutPath.'/vendor/some-package');
        File::ensureDirectoryExists($this->checkoutPath.'/.git');
        File::put($this->checkoutPath.'/composer.json', '{}');
        File::put($this->checkoutPath.'/app/Http/Controllers/CustomFieldController.php', '<?php');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutPath);
        parent::tearDown();
    }

    private function makeRepo(): RepoConfig
    {
        return RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => $this->checkoutPath, 'base_branch' => 'main',
        ]);
    }

    public function test_lists_dirs_before_files_excluding_git_and_vendor(): void
    {
        $repo = $this->makeRepo();

        $response = $this->getJson("/api/repo-configs/{$repo->id}/browse-files")->assertOk();

        $names = collect($response->json('entries'))->pluck('name')->all();
        $this->assertNotContains('.git', $names);
        $this->assertNotContains('vendor', $names);
        $this->assertContains('app', $names);
        $this->assertContains('composer.json', $names);

        $types = collect($response->json('entries'))->pluck('type', 'name');
        $this->assertSame('dir', $types['app']);
        $this->assertSame('file', $types['composer.json']);
        // Dirs sort before files: the last 'dir' entry's index must precede the first 'file' entry's.
        $orderedTypes = collect($response->json('entries'))->pluck('type');
        $this->assertLessThan($orderedTypes->search('file'), $orderedTypes->search('dir'));
    }

    public function test_navigates_into_a_subdirectory(): void
    {
        $repo = $this->makeRepo();

        $response = $this->getJson("/api/repo-configs/{$repo->id}/browse-files?path=app/Http/Controllers")->assertOk();

        $response->assertJsonPath('path', 'app/Http/Controllers');
        $names = collect($response->json('entries'))->pluck('name');
        $this->assertTrue($names->contains('CustomFieldController.php'));
    }

    public function test_rejects_a_path_traversal_attempt(): void
    {
        $repo = $this->makeRepo();

        $this->getJson("/api/repo-configs/{$repo->id}/browse-files?path=../../../../etc")
            ->assertStatus(404);
    }

    public function test_a_repo_never_checked_out_returns_a_clear_422(): void
    {
        // A GitHub-connected repo's checkout (RepoCheckoutManager::path() resolves it under
        // config('boschifai.var_path')) only exists on disk after RunWorkspaceManager::prepare()
        // has actually run once — a fresh connection with no run yet has nothing there.
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'acme', 'access_token' => 'gho_faketoken',
        ]);
        $repo = RepoConfig::create([
            'name' => 'never-run-repo', 'display_name' => 'acme/never-run-repo',
            'git_remote_path' => 'https://github.com/acme/never-run-repo.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id,
            'github_owner' => 'acme',
        ]);

        $this->getJson("/api/repo-configs/{$repo->id}/browse-files")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'checked out yet'));
    }
}
