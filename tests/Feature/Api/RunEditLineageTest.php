<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Jobs\RunGapAnalysisJob;
use App\Models\RepoConfig;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the "Edit" flow (plan J5): Runs are immutable, so editing a run never mutates it — it
 * creates a brand-new Run linked back via previous_run_id. These tests verify that lineage is
 * persisted and surfaced correctly, and that the FK is validated rather than accepted blindly.
 */
class RunEditLineageTest extends TestCase
{
    use RefreshDatabase;

    private function makeRepo(): RepoConfig
    {
        return RepoConfig::create([
            'name' => 'rams', 'display_name' => 'RAMS',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'prod',
            'docker_image' => 'rams-app:latest',
        ]);
    }

    private function makeRun(RepoConfig $repo, RunState $state, string $requirementText = 'x', ?string $previousRunId = null): Run
    {
        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => $requirementText,
            'target_file_path' => 'x.php',
            'state' => $state,
            'previous_run_id' => $previousRunId,
        ]);
    }

    public function test_creating_a_run_with_a_valid_previous_run_id_persists_and_returns_it(): void
    {
        // store() dispatches RunGapAnalysisJob, which is unrelated to lineage — faked so it
        // doesn't run synchronously (QUEUE_CONNECTION=sync in tests) against a real git remote.
        Queue::fake([RunGapAnalysisJob::class]);

        $repo = $this->makeRepo();
        $originalRun = $this->makeRun($repo, RunState::Failed);

        $response = $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'Fixed requirement text',
            'target_file_path' => 'app/Http/Controllers/Fixed.php',
            'previous_run_id' => $originalRun->id,
        ])->assertOk();

        $response->assertJsonPath('data.previous_run_id', $originalRun->id);
        $this->assertDatabaseHas('runs', [
            'requirement_text' => 'Fixed requirement text',
            'previous_run_id' => $originalRun->id,
        ]);
    }

    public function test_creating_a_run_with_an_unknown_previous_run_id_is_rejected(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);

        $repo = $this->makeRepo();

        $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'Some requirement',
            'target_file_path' => 'app/Http/Controllers/Whatever.php',
            'previous_run_id' => (string) Str::uuid(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('previous_run_id');
    }

    public function test_showing_a_run_with_no_previous_run_returns_null_lineage_fields(): void
    {
        $repo = $this->makeRepo();
        $run = $this->makeRun($repo, RunState::Draft);

        $this->getJson("/api/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.previous_run_id', null)
            ->assertJsonPath('data.previous_run_summary', null);
    }

    public function test_showing_a_run_with_a_previous_run_returns_the_previous_runs_summary(): void
    {
        $repo = $this->makeRepo();
        $originalRun = $this->makeRun($repo, RunState::Failed, "Original requirement first line\nmore detail below");
        $editedRun = $this->makeRun($repo, RunState::Draft, 'Fixed requirement text', $originalRun->id);

        $this->getJson("/api/runs/{$editedRun->id}")
            ->assertOk()
            ->assertJsonPath('data.previous_run_id', $originalRun->id)
            ->assertJsonPath('data.previous_run_summary', 'Original requirement first line');
    }
}
