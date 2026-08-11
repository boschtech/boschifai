<?php

namespace Tests\Feature\Api;

use App\Jobs\RunGapAnalysisJob;
use App\Models\RepoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * repo_config_id used to be implicit (hardcoded to the single "rams" row) — now that repos can
 * be connected via the GitHub App flow, it's a real required field, and a repo with no
 * docker_image configured must be rejected here rather than failing confusingly deep inside
 * the pipeline (see RunCreatePage.vue's matching disabled-submit UI state).
 */
class RunStoreRepoValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repo_config_id_is_required(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);

        $this->postJson('/api/runs', [
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
        ])->assertStatus(422)->assertJsonValidationErrors('repo_config_id');
    }

    public function test_repo_config_id_must_reference_a_real_repo_config(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);

        $this->postJson('/api/runs', [
            'repo_config_id' => 999999,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
        ])->assertStatus(422)->assertJsonValidationErrors('repo_config_id');
    }

    public function test_a_repo_with_no_docker_image_is_rejected_at_submission_time(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);

        $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
        ])->assertStatus(422);

        $this->assertDatabaseCount('runs', 0);
    }

    public function test_a_repo_with_a_docker_image_configured_is_accepted(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);

        $repo = RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
            'docker_image' => 'rams-app:latest',
        ]);

        $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
        ])->assertOk();

        Queue::assertPushed(RunGapAnalysisJob::class);
    }
}
