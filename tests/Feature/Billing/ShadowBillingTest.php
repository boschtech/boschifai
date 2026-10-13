<?php

namespace Tests\Feature\Billing;

use App\Enums\ChargeOutcome;
use App\Enums\RunState;
use App\Models\CreditLedgerEntry;
use App\Models\InvocationCharge;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Services\Billing\StepBilling;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Every finished Claude step is priced and recorded, but in shadow mode no credits move and a
 * pricing problem never affects the pipeline.
 */
class ShadowBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.markup' => '2', 'billing.min_charge_usd' => null, 'billing.stage_holds_usd.gap_analysis' => '0.50']);

        $workspace = Mockery::mock(RunWorkspaceManager::class);
        $workspace->shouldReceive('path')->andReturn(sys_get_temp_dir());
        $workspace->shouldReceive('statusPaths')->andReturn([]);
        $this->app->instance(RunWorkspaceManager::class, $workspace);
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create(['workspace_id' => $this->workspace()->id,
            'name' => 'backend', 'display_name' => 'acme/backend', 'git_remote_path' => '/tmp/x', 'base_branch' => 'main',
        ]);

        return Run::create(['repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => 'x.php', 'state' => RunState::GapAnalysisRunning]);
    }

    private function claudeReturns(ClaudeInvocationResult $result): void
    {
        $invoker = Mockery::mock(HeadlessClaudeInvoker::class)->makePartial();
        $invoker->shouldReceive('invoke')->andReturn($result);
        $this->app->instance(HeadlessClaudeInvoker::class, $invoker);
    }

    private function invocation(?float $cost, int $exitCode = 0, ?string $error = null): ClaudeInvocationResult
    {
        return new ClaudeInvocationResult(exitCode: $exitCode, timedOut: false, rawTranscriptPath: '', durationMs: 5, totalCostUsd: $cost, numTurns: 1, stopReason: null, stderr: '', resultError: $error);
    }

    public function test_a_finished_step_is_priced_but_no_credits_move(): void
    {
        $this->claudeReturns($this->invocation(0.1234));

        app(StepExecutionService::class)->run($run = $this->makeRun(), 'gap_analysis', '/boschifai-review', 60);

        $charge = InvocationCharge::sole();
        $this->assertSame($run->workspace_id, $charge->workspace_id);
        $this->assertSame('gap_analysis', $charge->step_key);
        $this->assertSame(ChargeOutcome::Charged, $charge->outcome);
        $this->assertSame(123_400, $charge->cost_micro);
        $this->assertSame(500_000, $charge->hold_micro);
        $this->assertSame(246_800, $charge->charge_micro, 'cost × 2 markup, under the hold');
        $this->assertSame(2_000_000, $charge->markup_millionths);
        $this->assertFalse($charge->enforced);
        $this->assertNull($charge->ledger_entry_id);
        $this->assertSame(0, CreditLedgerEntry::count(), 'Shadow mode never moves credits.');
    }

    public function test_the_charge_is_kept_when_its_run_is_deleted(): void
    {
        $this->claudeReturns($this->invocation(0.1));
        app(StepExecutionService::class)->run($run = $this->makeRun(), 'gap_analysis', '/boschifai-review', 60);

        $run->delete();

        $charge = InvocationCharge::sole();
        $this->assertNull($charge->claude_invocation_id);
        $this->assertSame(200_000, $charge->charge_micro);
    }

    public function test_a_platform_failure_is_recorded_as_free(): void
    {
        $this->claudeReturns($this->invocation(0.02, exitCode: 1, error: 'Claude API error (HTTP 529): Overloaded'));

        app(StepExecutionService::class)->run($this->makeRun(), 'gap_analysis', '/boschifai-review', 60);

        $this->assertSame([ChargeOutcome::Released, 0], [InvocationCharge::sole()->outcome, InvocationCharge::sole()->charge_micro]);
    }

    public function test_a_pricing_failure_is_reported_but_never_breaks_the_step(): void
    {
        $this->claudeReturns($this->invocation(0.1));
        $billing = Mockery::mock(StepBilling::class);
        $billing->shouldReceive('recordShadowCharge')->andThrow(new RuntimeException('pricing exploded'));
        $this->app->instance(StepBilling::class, $billing);
        $reported = [];
        $this->app->make(ExceptionHandler::class)
            ->reportable(function (RuntimeException $e) use (&$reported) {
                $reported[] = $e->getMessage();

                return false;
            });

        $outcome = app(StepExecutionService::class)->run($this->makeRun(), 'gap_analysis', '/boschifai-review', 60);

        $this->assertTrue($outcome->claudeResult->succeeded());
        $this->assertSame(['pricing exploded'], $reported);
        $this->assertSame(0, InvocationCharge::count());
    }
}
