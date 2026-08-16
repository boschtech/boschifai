<?php

namespace Tests\Feature\Api;

use App\Models\GithubConnection;
use App\Models\RepoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['boschifai.github.oauth_client_id' => 'client-123', 'boschifai.github.oauth_client_secret' => 'secret-abc']);
    }

    public function test_repositories_lists_from_github_and_flags_already_connected_ones(): void
    {
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'octocat', 'access_token' => 'gho_faketoken',
        ]);
        RepoConfig::create([
            'name' => 'backend', 'display_name' => 'octocat/backend',
            'git_remote_path' => 'https://github.com/octocat/backend.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id, 'github_owner' => 'octocat',
        ]);

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['full_name' => 'octocat/backend', 'name' => 'backend', 'private' => true, 'default_branch' => 'main'],
                ['full_name' => 'octocat/frontend', 'name' => 'frontend', 'private' => true, 'default_branch' => 'main'],
            ]),
        ]);

        $response = $this->getJson("/api/github/connections/{$connection->id}/repositories")->assertOk();

        $response->assertJsonFragment(['full_name' => 'octocat/backend', 'connected' => true]);
        $response->assertJsonFragment(['full_name' => 'octocat/frontend', 'connected' => false]);
    }

    public function test_connect_repositories_creates_repo_configs(): void
    {
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'octocat', 'access_token' => 'gho_faketoken',
        ]);

        $response = $this->postJson("/api/github/connections/{$connection->id}/repositories", [
            'repositories' => [
                ['full_name' => 'octocat/backend', 'default_branch' => 'main'],
            ],
        ])->assertOk();

        $response->assertJsonFragment(['name' => 'backend', 'display_name' => 'octocat/backend']);
        $this->assertDatabaseHas('repo_configs', [
            'name' => 'backend',
            'git_remote_path' => 'https://github.com/octocat/backend.git',
            'github_owner' => 'octocat',
            'github_connection_id' => $connection->id,
        ]);
    }

    public function test_connect_repositories_422s_on_a_name_collision_with_a_different_connection(): void
    {
        RepoConfig::create([
            'name' => 'backend', 'display_name' => 'RAMS-ish',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
        ]);

        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'octocat', 'access_token' => 'gho_faketoken',
        ]);

        $this->postJson("/api/github/connections/{$connection->id}/repositories", [
            'repositories' => [
                ['full_name' => 'octocat/backend', 'default_branch' => 'main'],
            ],
        ])->assertStatus(422);
    }

    public function test_exclude_organization_deletes_its_repo_configs_and_hides_it_from_future_listings(): void
    {
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'octocat', 'access_token' => 'gho_faketoken',
        ]);
        RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id, 'github_owner' => 'acme',
        ]);
        RepoConfig::create([
            'name' => 'frontend', 'display_name' => 'acme/frontend',
            'git_remote_path' => 'https://github.com/acme/frontend.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id, 'github_owner' => 'acme',
        ]);
        RepoConfig::create([
            'name' => 'unrelated', 'display_name' => 'octocat/unrelated',
            'git_remote_path' => 'https://github.com/octocat/unrelated.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id, 'github_owner' => 'octocat',
        ]);

        $this->deleteJson("/api/github/connections/{$connection->id}/organizations/acme")->assertNoContent();

        $this->assertDatabaseCount('repo_configs', 1);
        $this->assertDatabaseHas('repo_configs', ['name' => 'unrelated']);
        $this->assertDatabaseHas('excluded_github_organizations', [
            'github_connection_id' => $connection->id,
            'organization_login' => 'acme',
        ]);
    }

    public function test_repositories_omits_excluded_organizations_entirely(): void
    {
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'octocat', 'access_token' => 'gho_faketoken',
        ]);
        $connection->excludedOrganizations()->create(['organization_login' => 'acme']);

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['full_name' => 'acme/backend', 'name' => 'backend', 'private' => true, 'default_branch' => 'main'],
                ['full_name' => 'octocat/frontend', 'name' => 'frontend', 'private' => true, 'default_branch' => 'main'],
            ]),
        ]);

        $response = $this->getJson("/api/github/connections/{$connection->id}/repositories")->assertOk();

        $response->assertJsonMissing(['full_name' => 'acme/backend']);
        $response->assertJsonFragment(['full_name' => 'octocat/frontend']);
        $response->assertJsonFragment(['excluded_organizations' => ['acme']]);
    }

    public function test_restore_organization_makes_it_reappear(): void
    {
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'octocat', 'access_token' => 'gho_faketoken',
        ]);
        $connection->excludedOrganizations()->create(['organization_login' => 'acme']);

        $this->postJson("/api/github/connections/{$connection->id}/organizations/acme/restore")->assertNoContent();

        $this->assertDatabaseCount('excluded_github_organizations', 0);
    }
}
