<?php

namespace Tests\Feature\Api;

use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepoConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_repo_configs_with_the_fields_the_frontend_needs(): void
    {
        RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
            'docker_image' => 'rams-app:latest',
        ]);
        RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);

        $response = $this->getJson('/api/repo-configs')->assertOk();

        $response->assertJsonFragment(['name' => 'rams', 'has_docker_image' => true, 'connected_via_github' => false]);
        $response->assertJsonFragment(['name' => 'backend', 'has_docker_image' => false]);
        $response->assertJsonMissingPath('data.0.git_remote_path');
    }

    public function test_destroy_removes_a_repo_config_with_no_runs(): void
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);

        $this->deleteJson("/api/repo-configs/{$repo->id}")->assertNoContent();

        $this->assertDatabaseMissing('repo_configs', ['id' => $repo->id]);
    }

    public function test_destroy_is_blocked_when_runs_reference_the_repo_config(): void
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);
        Run::create([
            'repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => 'x.php',
            'state' => 'draft',
        ]);

        $this->deleteJson("/api/repo-configs/{$repo->id}")->assertStatus(422);

        $this->assertDatabaseHas('repo_configs', ['id' => $repo->id]);
    }

    public function test_destroy_404s_for_an_unknown_repo_config(): void
    {
        $this->deleteJson('/api/repo-configs/999999')->assertStatus(404);
    }
}
