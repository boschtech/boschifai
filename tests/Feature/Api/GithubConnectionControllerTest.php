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

    public function test_authorize_url_returns_a_github_oauth_url_with_a_state_param(): void
    {
        $response = $this->getJson('/api/github/authorize-url')->assertOk();

        $this->assertStringStartsWith(
            'https://github.com/login/oauth/authorize?',
            $response->json('data.url')
        );
        $this->assertStringContainsString('client_id=client-123', $response->json('data.url'));
    }

    public function test_authorize_url_422s_when_the_oauth_app_is_not_configured(): void
    {
        config(['boschifai.github.oauth_client_id' => null]);

        $this->getJson('/api/github/authorize-url')->assertStatus(422);
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
}
