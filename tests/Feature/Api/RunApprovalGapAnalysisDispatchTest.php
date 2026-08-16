<?php

namespace Tests\Feature\Api;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunType;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Gate 1 approval branches on run_type (plan's state-reuse mapping): requirement mode still
 * dispatches RunTestCaseGenerationJob (test cases don't exist yet); coverage mode already has
 * test cases from the test_plan slot (RunTestPlanJob's coverageTestDesign()), so it dispatches
 * RunCodeGenerationJob directly instead.
 */
class RunApprovalGapAnalysisDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(RunType $runType): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'run_type' => $runType,
            'requirement_text' => 'x',
            'target_file_path' => $runType === RunType::Coverage ? '' : 'x.php',
            'state' => RunState::GapAnalysisReady,
        ]);

        if ($runType === RunType::Coverage) {
            RunArtifact::makeFromContent($run->id, ArtifactKind::CodebaseKnowledgeBase, '.boschifai/codebase_knowledge_base_x.md', '# KB')->save();
            RunArtifact::makeFromContent($run->id, ArtifactKind::TestCasesMarkdown, '.boschifai/test_cases_x.md', '# Test cases')->save();
        } else {
            RunArtifact::makeFromContent($run->id, ArtifactKind::TestabilityReview, '.boschifai/testability_review_x.md', '# Review')->save();
            RunArtifact::makeFromContent($run->id, ArtifactKind::TestPlan, '.boschifai/test_plan_x.md', '# Plan')->save();
        }

        return $run->fresh();
    }

    public function test_coverage_mode_approval_dispatches_code_generation_directly(): void
    {
        Queue::fake([RunCodeGenerationJob::class, RunTestCaseGenerationJob::class]);
        $run = $this->makeRun(RunType::Coverage);

        $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", ['decision' => 'approved'])
            ->assertOk();

        Queue::assertPushed(RunCodeGenerationJob::class);
        Queue::assertNotPushed(RunTestCaseGenerationJob::class);
        $this->assertSame(RunState::GenerationRunning, $run->fresh()->state);
    }

    public function test_requirement_mode_approval_still_dispatches_test_case_generation(): void
    {
        Queue::fake([RunCodeGenerationJob::class, RunTestCaseGenerationJob::class]);
        $run = $this->makeRun(RunType::Requirement);

        $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", ['decision' => 'approved'])
            ->assertOk();

        Queue::assertPushed(RunTestCaseGenerationJob::class);
        Queue::assertNotPushed(RunCodeGenerationJob::class);
    }
}
