<?php

namespace Tests\Feature\Api;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Enums\RunType;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Request changes" at Gate 1 is not a rejection: it closes this run as
 * gap_analysis_changes_requested and starts a linked follow-up run that re-runs Gate 1 with the
 * reviewer's feedback (see RunApprovalController::startRevisedRun). "Reject" still ends the run.
 */
class RunApprovalGapAnalysisChangesRequestedTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(RunType $runType = RunType::Requirement, array $attachments = []): Run
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);

        $run = Run::create([
            'repo_config_id' => $repo->id,
            'run_type' => $runType,
            'requirement_text' => 'As a user I want refunds.',
            'target_file_path' => 'app/Services/RefundService.php',
            'attachment_filenames' => $attachments,
            'state' => RunState::GapAnalysisReady,
        ]);

        RunArtifact::makeFromContent($run->id, ArtifactKind::TestabilityReview, '.boschifai/testability_review_x.md', '# Review')->save();
        RunArtifact::makeFromContent($run->id, ArtifactKind::TestPlan, '.boschifai/test_plan_x.md', '# Plan')->save();

        return $run->fresh();
    }

    public function test_request_changes_closes_the_run_without_rejecting_it_and_starts_a_linked_rerun(): void
    {
        Queue::fake([RunGapAnalysisJob::class, RunTestCaseGenerationJob::class]);
        $run = $this->makeRun();

        $response = $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", [
            'decision' => 'changes_requested',
            'comment' => 'Also cover partial refunds.',
        ])->assertOk();

        $run->refresh();
        $this->assertSame(RunState::GapAnalysisChangesRequested, $run->state);
        $this->assertSame('Also cover partial refunds.', $run->rejection_comment);

        $revised = Run::where('previous_run_id', $run->id)->sole();
        $this->assertSame($revised->id, $response->json('data.revised_run_id'));
        $this->assertSame(RunState::Draft, $revised->state);
        $this->assertSame($run->repo_config_id, $revised->repo_config_id);
        $this->assertSame(RunType::Requirement, $revised->run_type);
        $this->assertSame('app/Services/RefundService.php', $revised->target_file_path);
        $this->assertStringStartsWith('As a user I want refunds.', $revised->requirement_text);
        $this->assertStringContainsString('Also cover partial refunds.', $revised->requirement_text);

        Queue::assertPushed(RunGapAnalysisJob::class, fn ($job) => $job->runId === $revised->id);
        Queue::assertNotPushed(RunTestCaseGenerationJob::class);
    }

    public function test_the_original_run_keeps_the_artifacts_that_were_reviewed(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $run = $this->makeRun();

        $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", [
            'decision' => 'changes_requested',
            'comment' => 'x',
        ])->assertOk();

        $this->assertSame(2, $run->artifacts()->count());
        $this->assertSame(0, Run::where('previous_run_id', $run->id)->sole()->artifacts()->count());
    }

    public function test_request_changes_works_for_coverage_runs_too(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $run = $this->makeRun(RunType::Coverage);

        $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", [
            'decision' => 'changes_requested',
            'comment' => 'Focus on the refund calculator.',
        ])->assertOk();

        $this->assertSame(RunType::Coverage, Run::where('previous_run_id', $run->id)->sole()->run_type);
    }

    public function test_attachments_are_copied_to_the_rerun(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $run = $this->makeRun(RunType::Requirement, ['abc12345_spec.md']);
        File::ensureDirectoryExists(storage_path("app/boschifai-runs/{$run->id}/attachments"));
        File::put(storage_path("app/boschifai-runs/{$run->id}/attachments/abc12345_spec.md"), '# Spec');

        try {
            $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", [
                'decision' => 'changes_requested',
                'comment' => 'x',
            ])->assertOk();

            $revised = Run::where('previous_run_id', $run->id)->sole();
            $this->assertSame(['abc12345_spec.md'], $revised->attachment_filenames);
            $this->assertFileExists(storage_path("app/boschifai-runs/{$revised->id}/attachments/abc12345_spec.md"));
        } finally {
            File::deleteDirectory(storage_path("app/boschifai-runs/{$run->id}"));
            if (isset($revised)) {
                File::deleteDirectory(storage_path("app/boschifai-runs/{$revised->id}"));
            }
        }
    }

    public function test_request_changes_still_requires_a_comment(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $run = $this->makeRun();

        $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", ['decision' => 'changes_requested'])
            ->assertStatus(422)->assertJsonValidationErrors('comment');

        $this->assertSame(RunState::GapAnalysisReady, $run->fresh()->state);
        $this->assertSame(0, Run::where('previous_run_id', $run->id)->count());
    }

    public function test_reject_still_ends_the_run_with_no_rerun(): void
    {
        Queue::fake([RunGapAnalysisJob::class]);
        $run = $this->makeRun();

        $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", [
            'decision' => 'rejected',
            'comment' => 'Not worth testing.',
        ])->assertOk()->assertJsonPath('data.revised_run_id', null);

        $this->assertSame(RunState::GapAnalysisRejected, $run->fresh()->state);
        $this->assertSame(0, Run::where('previous_run_id', $run->id)->count());
        Queue::assertNotPushed(RunGapAnalysisJob::class);
    }
}
