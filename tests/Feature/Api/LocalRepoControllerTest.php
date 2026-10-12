<?php

namespace Tests\Feature\Api;

use App\Models\RepoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/**
 * LocalRepoController: browsing/connecting repos already on disk, always confined to the
 * configured `boschifai.local_repos.root_path`.
 */
class LocalRepoControllerTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/boschifai-local-repos-'.uniqid();
        File::ensureDirectoryExists($this->root.'/alpha/.git');
        File::ensureDirectoryExists($this->root.'/beta');
        File::ensureDirectoryExists($this->root.'/.hidden');
        File::ensureDirectoryExists($this->root.'/group/nested/.git');
        File::put($this->root.'/README.md', 'not a directory');

        // realpath() so assertions match what the controller stores (macOS /var -> /private/var).
        $this->root = realpath($this->root);
        config(['boschifai.local_repos.root_path' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_browse_lists_only_visible_directories_sorted_with_git_and_connection_flags(): void
    {
        $connected = RepoConfig::create([
            'name' => 'alpha', 'display_name' => 'alpha',
            'git_remote_path' => $this->root.'/alpha', 'base_branch' => 'main',
        ]);

        $response = $this->getJson('/api/local-repos/browse')->assertOk();

        $this->assertSame('', $response->json('path'));
        $this->assertSame([
            ['name' => 'alpha', 'path' => 'alpha', 'is_git_repo' => true, 'connected' => true, 'repo_config_id' => $connected->id],
            ['name' => 'beta', 'path' => 'beta', 'is_git_repo' => false, 'connected' => false, 'repo_config_id' => null],
            ['name' => 'group', 'path' => 'group', 'is_git_repo' => false, 'connected' => false, 'repo_config_id' => null],
        ], $response->json('entries'));
    }

    public function test_browse_into_a_subdirectory_returns_paths_relative_to_the_root(): void
    {
        $response = $this->getJson('/api/local-repos/browse?path=group')->assertOk();

        $this->assertSame('group', $response->json('path'));
        $this->assertSame('group/nested', $response->json('entries.0.path'));
        $this->assertTrue($response->json('entries.0.is_git_repo'));
    }

    public function test_browse_rejects_paths_escaping_the_root(): void
    {
        $this->getJson('/api/local-repos/browse?path=../')->assertNotFound();
        $this->getJson('/api/local-repos/browse?path=does-not-exist')->assertNotFound();
    }

    public function test_browse_rejects_a_file_rather_than_a_directory(): void
    {
        $this->getJson('/api/local-repos/browse?path=README.md')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Not a directory.']);
    }

    public function test_browse_500s_with_a_helpful_message_when_the_root_does_not_exist(): void
    {
        config(['boschifai.local_repos.root_path' => $this->root.'/missing']);

        $response = $this->getJson('/api/local-repos/browse')->assertStatus(500);

        $this->assertStringContainsString('BOSCHIFAI_LOCAL_REPOS_ROOT_PATH', $response->json('message'));
    }

    public function test_connect_creates_a_local_repo_config_using_the_detected_branch(): void
    {
        Process::fake([
            '*' => Process::result(output: "develop\n"),
        ]);

        $response = $this->postJson('/api/local-repos/connect', [
            'repositories' => [['path' => 'alpha'], ['path' => 'group/nested']],
        ])->assertSuccessful();

        $this->assertCount(2, $response->json('data'));
        $this->assertDatabaseHas('repo_configs', [
            'name' => 'alpha',
            'display_name' => 'alpha',
            'git_remote_path' => $this->root.'/alpha',
            'base_branch' => 'develop',
            'github_connection_id' => null,
        ]);
        $this->assertDatabaseHas('repo_configs', [
            'name' => 'nested',
            'git_remote_path' => $this->root.'/group/nested',
        ]);
        Process::assertRanTimes(fn ($process) => $process->command === ['git', 'rev-parse', '--abbrev-ref', 'HEAD'], 2);
    }

    public function test_connect_falls_back_to_main_when_branch_detection_fails(): void
    {
        Process::fake([
            '*' => Process::result(exitCode: 128, errorOutput: 'fatal: not a git repository'),
        ]);

        $this->postJson('/api/local-repos/connect', ['repositories' => [['path' => 'alpha']]])
            ->assertSuccessful();

        $this->assertDatabaseHas('repo_configs', ['name' => 'alpha', 'base_branch' => 'main']);
    }

    public function test_connecting_the_same_repo_twice_updates_rather_than_duplicates(): void
    {
        Process::fake(['*' => Process::result(output: "main\n")]);

        $this->postJson('/api/local-repos/connect', ['repositories' => [['path' => 'alpha']]])->assertSuccessful();
        $this->postJson('/api/local-repos/connect', ['repositories' => [['path' => 'alpha']]])->assertSuccessful();

        $this->assertSame(1, RepoConfig::where('name', 'alpha')->count());
    }

    public function test_connect_rejects_a_directory_without_a_git_folder(): void
    {
        Process::fake();

        $this->postJson('/api/local-repos/connect', ['repositories' => [['path' => 'beta']]])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "'beta' doesn't look like a git repository (no .git found)."]);

        $this->assertDatabaseCount('repo_configs', 0);
    }

    public function test_connect_rejects_a_name_clash_with_a_repo_connected_from_elsewhere(): void
    {
        Process::fake();
        RepoConfig::create([
            'name' => 'alpha', 'display_name' => 'acme/alpha',
            'git_remote_path' => 'https://github.com/acme/alpha.git', 'base_branch' => 'main',
        ]);

        $response = $this->postJson('/api/local-repos/connect', ['repositories' => [['path' => 'alpha']]])
            ->assertStatus(422);

        $this->assertStringContainsString("A repository named 'alpha' is already connected", $response->json('message'));
        $this->assertDatabaseHas('repo_configs', ['name' => 'alpha', 'git_remote_path' => 'https://github.com/acme/alpha.git']);
    }

    public function test_connect_rejects_paths_escaping_the_root(): void
    {
        Process::fake();

        $this->postJson('/api/local-repos/connect', ['repositories' => [['path' => '../../etc']]])
            ->assertNotFound();
    }

    public function test_connect_validates_the_payload(): void
    {
        $this->postJson('/api/local-repos/connect', ['repositories' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['repositories']);

        $this->postJson('/api/local-repos/connect', ['repositories' => [['nope' => 'x']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['repositories.0.path']);
    }

    public function test_browsing_and_connecting_are_unavailable_when_local_repos_are_disabled(): void
    {
        config(['boschifai.features.local_repos' => false]);

        $this->getJson('/api/local-repos/browse')
            ->assertNotFound()
            ->assertJsonPath('message', 'Local repositories are disabled on this installation.');
        $this->postJson('/api/local-repos/connect', ['repositories' => [['path' => 'alpha']]])
            ->assertNotFound();

        $this->assertSame(0, RepoConfig::count());
    }
}
