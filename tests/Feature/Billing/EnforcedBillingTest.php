<?php

namespace Tests\Feature\Billing;

use App\Enums\ChargeOutcome;
use App\Enums\CreditEntryType;
use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Jobs\RunGapAnalysisJob;
use App\Jobs\RunTestPlanJob;
use App\Models\ClaudeInvocation;
use App\Models\CreditLedgerEntry;
use App\Models\InvocationCharge;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\Billing\CreditLedger;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Pipeline\StepOutcome;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * With billing.enforce on, each Claude step reserves its hold first, is charged from it when it
 * finishes, and a run that can't cover a step waits in awaiting_credits instead of running.
 */
class EnforcedBillingTest extends TestCase
{
    use RefreshDatabase;

    private string $checkout;

    private int $invocations = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce' => true, 'billing.markup' => '2', 'billing.min_charge_usd' => null, 'billing.stage_holds_usd.gap_analysis' => '1.00']);

        $this->checkout = sys_get_temp_dir().'/boschifai-enforced-'.uniqid();
        File::ensureDirectoryExists($this->checkout.'/.boschifai');
        $workspace = Mockery::mock(RunWorkspaceManager::class);
        $workspace->shouldReceive('path')->andReturn($this->checkout);
        $workspace->shouldReceive('prepare')->andReturn($this->checkout);
        $workspace->shouldReceive('statusPaths')->andReturn([]);
        $this->app->instance(RunWorkspaceManager::class, $workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkout);
        parent::tearDown();
    }

    private function fund(int $micro): void
    {
        app(CreditLedger::class)->adjust($this->workspace(), $micro, null, 'Test credit', 'fund-'.uniqid());
    }

    private function available(): int
    {
        return app(CreditLedger::class)->balance($this->workspace())->available_micro;
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create(['workspace_id' => $this->workspace()->id,
            'name' => 'backend', 'display_name' => 'acme/backend', 'git_remote_path' => '/tmp/x', 'base_branch' => 'main',
        ]);

        return Run::create(['repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => 'x.php', 'state' => RunState::Draft]);
    }

    private function claude(callable|ClaudeInvocationResult $returns): void
    {
        $invoker = Mockery::mock(HeadlessClaudeInvoker::class)->makePartial();
        $invoker->shouldReceive('invoke')->andReturnUsing(function () use ($returns) {
            $this->invocations++;

            return is_callable($returns) ? $returns() : $returns;
        });
        $this->app->instance(HeadlessClaudeInvoker::class, $invoker);
    }

    private function invocation(?float $cost = 0.2, int $exitCode = 0, ?string $error = null): ClaudeInvocationResult
    {
        return new ClaudeInvocationResult(exitCode: $exitCode, timedOut: false, rawTranscriptPath: '', durationMs: 5, totalCostUsd: $cost, numTurns: 1, stopReason: null, stderr: '', resultError: $error);
    }

    private function runStep(Run $run): StepOutcome
    {
        return app(StepExecutionService::class)->run($run, 'gap_analysis', '/boschifai-review', 60);
    }

    public function test_a_funded_step_is_charged_from_its_hold_and_the_rest_returned(): void
    {
        $this->fund(5_000_000);
        $this->claude($this->invocation(0.2));

        $outcome = $this->runStep($this->makeRun());

        $this->assertTrue($outcome->claudeResult->succeeded());
        $this->assertFalse($outcome->halted);
        $this->assertSame(4_600_000, $this->available(), 'US$5 − (US$0.20 × 2)');
        $this->assertSame(0, app(CreditLedger::class)->balance($this->workspace())->held_micro);
        $charge = InvocationCharge::sole();
        $this->assertTrue($charge->enforced);
        $this->assertSame(400_000, $charge->charge_micro);
        $this->assertSame(CreditEntryType::Charge, CreditLedgerEntry::find($charge->ledger_entry_id)->type);
        $this->assertTrue(app(CreditLedger::class)->isConsistent($this->workspace()));
    }

    public function test_a_platform_fault_costs_nothing(): void
    {
        $this->fund(5_000_000);
        $this->claude($this->invocation(0.2, exitCode: 1, error: 'Claude API error (HTTP 529): Overloaded'));

        $this->runStep($this->makeRun());

        $this->assertSame(5_000_000, $this->available());
        $this->assertSame([ChargeOutcome::Released, 0, null], [InvocationCharge::sole()->outcome, InvocationCharge::sole()->charge_micro, InvocationCharge::sole()->ledger_entry_id]);
    }

    public function test_a_step_the_workspace_cannot_afford_waits_for_credits_instead_of_running(): void
    {
        $this->fund(500_000);
        $this->claude($this->invocation());
        $run = $this->makeRun();

        $outcome = $this->runStep($run);

        $this->assertTrue($outcome->halted);
        $this->assertSame(0, $this->invocations, 'Claude is never started.');
        $this->assertSame(0, ClaudeInvocation::count());
        $run->refresh();
        $this->assertSame(RunState::AwaitingCredits, $run->state);
        $this->assertSame('gap_analysis', $run->awaiting_credits_step);
        $message = 'Not enough credits: US$1.00 needed, US$0.50 available. Add credits to this workspace, then resume the run.';
        $this->assertSame($message, $run->error_message);
        $step = $run->steps()->sole();
        $this->assertSame([RunStepStatus::Pending, $message], [$step->status, $step->error_message]);
        $this->assertSame(500_000, $this->available());
    }

    public function test_the_job_stops_while_waiting_and_does_not_fail_the_run_or_move_on(): void
    {
        Queue::fake([RunTestPlanJob::class]);
        $this->claude($this->invocation());
        $run = $this->makeRun();

        app()->call([new RunGapAnalysisJob($run->id), 'handle']);

        $this->assertSame(RunState::AwaitingCredits, $run->fresh()->state);
        Queue::assertNotPushed(RunTestPlanJob::class);
    }

    public function test_a_crash_returns_the_whole_hold(): void
    {
        $this->fund(5_000_000);
        $this->claude(fn () => throw new RuntimeException('worker lost'));

        try {
            $this->runStep($this->makeRun());
            $this->fail('Expected the crash to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('worker lost', $e->getMessage());
        }

        $this->assertSame(5_000_000, $this->available());
        $this->assertSame(0, app(CreditLedger::class)->balance($this->workspace())->held_micro);
        $this->assertTrue(CreditLedgerEntry::where('type', CreditEntryType::Release)->exists());
    }

    public function test_a_step_with_no_hold_configured_runs_uncharged_and_is_reported(): void
    {
        config(['billing.stage_holds_usd.gap_analysis' => null]);
        $this->claude($this->invocation(0.2));
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported) {
            $reported[] = $e->getMessage();

            return false;
        });

        $outcome = $this->runStep($this->makeRun());

        $this->assertTrue($outcome->claudeResult->succeeded());
        $this->assertSame(['Billing is enforced but no hold is set for step "gap_analysis" (BILLING_HOLD_GAP_ANALYSIS_USD); it ran without being charged.'], $reported);
        $this->assertSame(0, CreditLedgerEntry::count());
        $this->assertFalse(InvocationCharge::sole()->enforced);
    }

    public function test_shadow_mode_never_holds_or_blocks(): void
    {
        config(['billing.enforce' => false]);
        $this->claude($this->invocation(0.2));

        $outcome = $this->runStep($this->makeRun());

        $this->assertFalse($outcome->halted);
        $this->assertSame(1, $this->invocations);
        $this->assertSame(0, CreditLedgerEntry::count());
    }
}
