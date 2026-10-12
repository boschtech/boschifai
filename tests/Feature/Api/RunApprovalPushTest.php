<?php

namespace Tests\Feature\Api;

use App\Enums\ArtifactKind;
use App\Enums\RunState;
use App\Jobs\PushAndOpenPrJob;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/** Gate 2 (pre-push) approval endpoint: POST /api/runs/{run}/approvals/push. */
class RunApprovalPushTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private function makeRun(RunState $state = RunState::LocalExecutionComplete): Run
    {
        $repo = RepoConfig::create(['workspace_id' => $this->workspace()->id,
            'name' => 'backend', 'display_name' => 'acme/backend',
            'git_remote_path' => 'https://github.com/acme/backend.git', 'base_branch' => 'main',
        ]);

        return Run::create([
            'repo_config_id' => $repo->id,
            'requirement_text' => 'x',
            'target_file_path' => 'app/X.php',
            'state' => $state,
        ]);
    }

    public function test_approving_records_the_approval_against_the_generated_code_hash_and_dispatches_the_push(): void
    {
        Queue::fake();
        $run = $this->makeRun();
        RunArtifact::makeFromContent($run->id, ArtifactKind::GeneratedTest, 'tests/Feature/XTest.php', '<?php // test')->save();

        $this->postJson("/api/runs/{$run->id}/approvals/push", ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.state', 'pushing');

        $this->assertSame(RunState::Pushing, $run->fresh()->state);
        $this->assertDatabaseHas('human_approvals', [
            'run_id' => $run->id,
            'gate' => 'pre_push',
            'decision' => 'approved',
            'approved_artifact_hash' => hash('sha256', '<?php // test'),
            'comment' => null,
        ]);
        Queue::assertPushed(PushAndOpenPrJob::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_rejecting_requires_a_comment_and_stores_it_without_pushing(): void
    {
        Queue::fake();
        $run = $this->makeRun();

        $this->postJson("/api/runs/{$run->id}/approvals/push", ['decision' => 'rejected'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);

        $this->postJson("/api/runs/{$run->id}/approvals/push", ['decision' => 'rejected', 'comment' => 'Tests are too shallow.'])
            ->assertOk();

        $run->refresh();
        $this->assertSame(RunState::PushRejected, $run->state);
        $this->assertSame('Tests are too shallow.', $run->rejection_comment);
        $this->assertDatabaseHas('human_approvals', [
            'run_id' => $run->id,
            'decision' => 'rejected',
            // No generated artifact: hash of the empty string.
            'approved_artifact_hash' => hash('sha256', ''),
        ]);
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_decision_is_rejected(): void
    {
        $run = $this->makeRun();

        $this->postJson("/api/runs/{$run->id}/approvals/push", ['decision' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }

    public function test_a_run_not_at_the_push_gate_cannot_be_decided(): void
    {
        Queue::fake();
        $run = $this->makeRun(RunState::GenerationRunning);

        $this->postJson("/api/runs/{$run->id}/approvals/push", ['decision' => 'approved'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Run is not awaiting the push gate.']);

        $this->assertDatabaseCount('human_approvals', 0);
        Queue::assertNothingPushed();
    }
}
