<?php

namespace Tests\Feature\Api;

use App\Enums\RunType;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunStandaloneActionJob;
use App\Models\RepoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The two "Standalone Actions" (SidebarNav) need nothing beyond a repo pick — no requirement
 * text, no target file — so StoreRunRequest::prepareForValidation() must fill both in server-side
 * (same mechanism coverage mode's own '' target_file_path placeholder already uses), and
 * RunController::store() must dispatch RunStandaloneActionJob instead of RunGapAnalysisJob for
 * these two types.
 */
class RunStoreStandaloneActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeRepo(): RepoConfig
    {
        return RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);
    }

    /** @dataProvider standaloneTypesProvider */
    public function test_a_standalone_action_is_accepted_with_only_a_repo_picked(string $runType): void
    {
        // Both faked (not just RunStandaloneActionJob): if the run_type-branch in
        // RunController::store() ever regressed to dispatching RunGapAnalysisJob for these
        // types, that job actually running for real (Claude invocation, checkout prep, ...)
        // would be a much worse failure mode than a clean assertNotPushed() mismatch.
        Queue::fake([RunStandaloneActionJob::class, RunGapAnalysisJob::class]);
        $repo = $this->makeRepo();

        $response = $this->postJson('/api/runs', [
            'repo_config_id' => $repo->id,
            'run_type' => $runType,
        ])->assertOk();

        $this->assertSame($runType, $response->json('data.run_type'));
        $this->assertNotEmpty($response->json('data.requirement_summary'));
        $this->assertDatabaseHas('runs', [
            'id' => $response->json('data.id'),
            'run_type' => $runType,
            'target_file_path' => '',
        ]);

        Queue::assertPushed(RunStandaloneActionJob::class);
        Queue::assertNotPushed(RunGapAnalysisJob::class);
    }

    public static function standaloneTypesProvider(): array
    {
        return [
            'build skills' => [RunType::BuildSkills->value],
            'build knowledge base' => [RunType::BuildKnowledgeBase->value],
        ];
    }
}
