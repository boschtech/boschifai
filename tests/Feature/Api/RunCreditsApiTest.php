<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Jobs\PushAndOpenPrJob;
use App\Jobs\RunCodeGenerationJob;
use App\Jobs\RunFixFailingTestsJob;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunStandaloneActionJob;
use App\Jobs\RunTestCaseGenerationJob;
use App\Models\HumanApproval;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\TestExecutionResult;
use App\Services\Billing\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/**
 * With billing.enforce on, actions that would start a Claude step are refused with 402 when the
 * workspace can't cover that step's hold, and a run waiting for credits can be resumed or
 * cancelled.
 */
class RunCreditsApiTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private const NOT_ENOUGH = 'Not enough credits: US$1.00 needed, US$0.00 available. Ask a workspace owner or admin to add credits.';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'billing.enforce' => true,
            'billing.stage_holds_usd' => array_fill_keys(['gap_analysis', 'test_plan', 'test_case_generation', 'code_generation', 'fix_failing_tests', 'push', 'build_skills', 'build_knowledge_base'], '1.00'),
            'boschifai.features.github_mcp_push' => false,
        ]);
    }

    private function fund(): void
    {
        app(CreditLedger::class)->adjust($this->workspace(), 5_000_000, null, 'Test credit', 'fund');
    }

    private function repo(): RepoConfig
    {
        return RepoConfig::firstOrCreate(['workspace_id' => $this->workspace()->id, 'name' => 'backend'], [
            'display_name' => 'acme/backend', 'git_remote_path' => '/tmp/x', 'base_branch' => 'main',
        ]);
    }

    private function makeRun(RunState $state, array $attributes = []): Run
    {
        return Run::create(['repo_config_id' => $this->repo()->id, 'requirement_text' => 'x', 'target_file_path' => 'x.php', 'state' => $state, ...$attributes]);
    }

    private function awaiting(string $step = 'code_generation'): Run
    {
        $run = $this->makeRun(RunState::AwaitingCredits, ['awaiting_credits_step' => $step, 'error_message' => 'Not enough credits.']);
        RunStep::create(['run_id' => $run->id, 'key' => $step, 'status' => RunStepStatus::Pending]);

        return $run;
    }

    private function assertNotEnoughCredits($response): void
    {
        $response->assertStatus(402)->assertExactJson([
            'message' => self::NOT_ENOUGH,
            'code' => 'insufficient_credits',
            'available_usd' => '0.00',
            'required_usd' => '1.00',
        ]);
    }

    public function test_a_new_run_needs_credits_for_its_first_step(): void
    {
        $this->assertNotEnoughCredits($this->postJson('/api/runs', ['repo_config_id' => $this->repo()->id, 'requirement_text' => 'Add a lease export']));
        $this->assertNotEnoughCredits($this->postJson('/api/runs', ['repo_config_id' => $this->repo()->id, 'run_type' => 'build_skills']));
        $this->assertSame(0, Run::count());
        Queue::assertNothingPushed();

        $this->fund();
        $this->postJson('/api/runs', ['repo_config_id' => $this->repo()->id, 'requirement_text' => 'Add a lease export'])->assertOk();
        Queue::assertPushed(RunGapAnalysisJob::class);
    }

    public function test_retrying_a_step_needs_credits_but_local_execution_does_not(): void
    {
        $run = $this->makeRun(RunState::Failed, ['failed_step' => 'code_generation']);
        $step = RunStep::create(['run_id' => $run->id, 'key' => 'code_generation', 'status' => RunStepStatus::Failed]);

        $this->assertNotEnoughCredits($this->postJson("/api/runs/{$run->id}/steps/{$step->id}/retry"));
        $this->assertSame(RunState::Failed, $run->fresh()->state);

        $local = RunStep::create(['run_id' => $run->id, 'key' => 'local_execution', 'status' => RunStepStatus::Failed]);
        $this->postJson("/api/runs/{$run->id}/steps/{$local->id}/retry")->assertOk();
        $this->assertSame(RunState::LocalExecutionRunning, $run->fresh()->state);
    }

    public function test_a_step_that_cannot_be_started_on_its_own_is_still_refused_with_422(): void
    {
        $run = $this->makeRun(RunState::Failed, ['failed_step' => 'ci_poll']);
        $step = RunStep::create(['run_id' => $run->id, 'key' => 'ci_poll', 'status' => RunStepStatus::Failed]);

        $this->postJson("/api/runs/{$run->id}/steps/{$step->id}/retry")
            ->assertStatus(422)
            ->assertJsonPath('message', "Step 'ci_poll' cannot be retried directly — resubmit as a new run.");
    }

    public function test_fixing_failing_tests_needs_credits(): void
    {
        $run = $this->makeRun(RunState::LocalExecutionComplete, ['generated_file_path' => 'tests/XTest.php']);
        TestExecutionResult::create(['run_id' => $run->id, 'total' => 3, 'passed' => 1, 'failed' => 2, 'skipped' => 0, 'duration_ms' => 1, 'tests' => []]);

        $this->assertNotEnoughCredits($this->postJson("/api/runs/{$run->id}/local-execution/fix-failing-tests"));
        Queue::assertNotPushed(RunFixFailingTestsJob::class);
    }

    public function test_approving_gate_1_needs_credits_and_a_refusal_leaves_the_gate_open(): void
    {
        $run = $this->makeRun(RunState::GapAnalysisReady);

        $this->assertNotEnoughCredits($this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", ['decision' => 'approved']));
        $this->assertSame(RunState::GapAnalysisReady, $run->fresh()->state);
        $this->assertSame(0, HumanApproval::count());

        // Rejecting starts nothing, so it never needs credits.
        $this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", ['decision' => 'rejected', 'comment' => 'No'])->assertOk();

        $this->fund();
        $coverage = $this->makeRun(RunState::GapAnalysisReady, ['run_type' => 'coverage', 'repo_config_id' => RepoConfig::create(['workspace_id' => $this->workspace()->id, 'name' => 'other', 'display_name' => 'other', 'git_remote_path' => '/tmp/y', 'base_branch' => 'main'])->id]);
        $this->postJson("/api/runs/{$coverage->id}/approvals/gap-analysis", ['decision' => 'approved'])->assertOk();
        Queue::assertPushed(RunCodeGenerationJob::class);
        Queue::assertNotPushed(RunTestCaseGenerationJob::class);
    }

    public function test_requesting_changes_needs_credits_for_the_new_gap_analysis(): void
    {
        $run = $this->makeRun(RunState::GapAnalysisReady);

        $this->assertNotEnoughCredits($this->postJson("/api/runs/{$run->id}/approvals/gap-analysis", ['decision' => 'changes_requested', 'comment' => 'Cover renewals too']));
        $this->assertSame(1, Run::count());
    }

    public function test_pushing_needs_credits_only_when_the_push_runs_claude(): void
    {
        $run = $this->makeRun(RunState::LocalExecutionComplete);
        $this->postJson("/api/runs/{$run->id}/approvals/push", ['decision' => 'approved'])->assertOk();
        Queue::assertPushed(PushAndOpenPrJob::class);

        config(['boschifai.features.github_mcp_push' => true, 'boschifai.github_mcp.pat' => 'ghp_test']);
        $other = $this->makeRun(RunState::LocalExecutionComplete, ['repo_config_id' => RepoConfig::create(['workspace_id' => $this->workspace()->id, 'name' => 'other', 'display_name' => 'other', 'git_remote_path' => '/tmp/y', 'base_branch' => 'main'])->id]);
        $this->assertNotEnoughCredits($this->postJson("/api/runs/{$other->id}/approvals/push", ['decision' => 'approved']));
    }

    public function test_nothing_is_refused_in_shadow_mode(): void
    {
        config(['billing.enforce' => false]);

        $this->postJson('/api/runs', ['repo_config_id' => $this->repo()->id, 'run_type' => 'build_skills'])->assertOk();
        Queue::assertPushed(RunStandaloneActionJob::class);
    }

    public function test_a_waiting_run_resumes_its_step_once_there_are_credits(): void
    {
        $run = $this->awaiting('code_generation');

        $this->assertNotEnoughCredits($this->postJson("/api/runs/{$run->id}/resume"));
        $this->assertSame(RunState::AwaitingCredits, $run->fresh()->state);

        $this->fund();
        $this->postJson("/api/runs/{$run->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.state', 'generation_running')
            ->assertJsonPath('data.awaiting_credits_step', null)
            ->assertJsonPath('data.error_message', null);
        Queue::assertPushed(RunCodeGenerationJob::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_only_a_waiting_unarchived_run_can_be_resumed(): void
    {
        $this->fund();
        $failed = $this->makeRun(RunState::Failed);
        $this->postJson("/api/runs/{$failed->id}/resume")->assertStatus(422)->assertJsonPath('message', 'Run is not waiting for credits.');

        $archived = $this->awaiting();
        $archived->update(['archived_at' => now()]);
        $this->postJson("/api/runs/{$archived->id}/resume")->assertStatus(422)->assertJsonPath('message', 'Restore this run from the archive before resuming it.');

        Queue::assertNothingPushed();
    }

    public function test_a_waiting_run_is_cancelled_straight_away(): void
    {
        $run = $this->awaiting('code_generation');

        $this->postJson("/api/runs/{$run->id}/cancel")->assertOk()->assertJsonPath('data.state', 'cancelled');

        $run->refresh();
        $this->assertNull($run->awaiting_credits_step);
        $step = $run->steps()->sole();
        $this->assertSame([RunStepStatus::Cancelled, 'Cancelled by user while waiting for credits.'], [$step->status, $step->error_message]);
    }
}
