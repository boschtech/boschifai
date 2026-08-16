<?php

namespace Tests\Feature\Api;

use App\Jobs\RunGapAnalysisJob;
use App\Models\RepoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * repo_config_id used to be implicit (hardcoded to the single "rams" row) — now that repos can
 * be connected via GitHub OAuth or a local checkout, it's a real required field.
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

    public function test_a_repo_with_no_other_configuration_is_accepted(): void
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
        ])->assertOk();

        Queue::assertPushed(RunGapAnalysisJob::class);
    }

    public function test_a_second_run_against_a_repo_with_one_already_in_progress_is_rejected(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);

        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);
        \App\Models\Run::create([
            'repo_config_id' => $repo->id, 'requirement_text' => 'first', 'target_file_path' => 'x.php',
            'state' => \App\Enums\RunState::GapAnalysisRunning,
        ]);

        $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'second',
            'target_file_path' => 'x.php',
        ])->assertStatus(422);

        $this->assertDatabaseCount('runs', 1);
    }
}
