<?php

namespace Tests\Feature\Api;

use App\Enums\RunType;
use App\Jobs\RunGapAnalysisJob;
use App\Models\RepoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coverage mode (plan: "Coverage-Instruction Pipeline") has no known target file at submission
 * time — the pipeline works it out itself during test_plan (see RunTestPlanJob's
 * RECOMMENDED_TARGET_FILE extraction) — so target_file_path must be optional here. Requirement
 * mode is optional too (code generation infers the target from the approved test cases).
 */
class RunStoreCoverageModeTest extends TestCase
{
    use RefreshDatabase;

    private function makeRepo(): RepoConfig
    {
        return RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);
    }

    public function test_a_coverage_run_is_accepted_with_no_target_file_path(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $response = $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'run_type' => 'coverage',
            'requirement_text' => 'Increase test coverage for the billing module.',
        ])->assertOk();

        $this->assertSame('coverage', $response->json('data.run_type'));
        $this->assertDatabaseHas('runs', [
            'id' => $response->json('data.id'),
            'run_type' => RunType::Coverage->value,
            'target_file_path' => '',
        ]);

        Queue::assertPushed(RunGapAnalysisJob::class);
    }

    public function test_requirement_mode_accepts_a_missing_target_file_path(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $response = $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'run_type' => 'requirement',
            'requirement_text' => 'As a user I want...',
        ])->assertOk();

        $this->assertDatabaseHas('runs', [
            'id' => $response->json('data.id'),
            'run_type' => RunType::Requirement->value,
            'target_file_path' => '',
        ]);
    }

    public function test_omitting_run_type_defaults_to_requirement_and_accepts_a_missing_target_file_path(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $response = $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => 'As a user I want...',
        ])->assertOk();

        $this->assertDatabaseHas('runs', [
            'id' => $response->json('data.id'),
            'run_type' => RunType::Requirement->value,
            'target_file_path' => '',
        ]);
    }

    public function test_an_invalid_run_type_is_rejected(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'run_type' => 'not-a-real-type',
            'requirement_text' => 'x',
            'target_file_path' => 'x.php',
        ])->assertStatus(422)->assertJsonValidationErrors('run_type');
    }

    public function test_requirement_text_up_to_100000_characters_is_accepted(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => str_repeat('a', 100000),
        ])->assertOk();
    }

    public function test_requirement_text_over_100000_characters_is_rejected(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'requirement_text' => str_repeat('a', 100001),
        ])->assertStatus(422)->assertJsonValidationErrors('requirement_text');
    }
}
