<?php

namespace Tests\Feature\Billing;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Enums\RunState;
use App\Models\ClaudeInvocation;
use App\Models\CreditLedgerEntry;
use App\Models\InvocationCharge;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\Workspace;
use App\Models\WorkspaceAiCredential;
use App\Services\Billing\CreditGate;
use App\Services\Billing\InsufficientCredits;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Services\ClaudeRunner\Credentials\ClaudeCredentialResolver;
use App\Services\ClaudeRunner\Credentials\ResolvedClaudeCredentials;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A workspace on its own Anthropic key runs on that key, is billed by Anthropic, and never
 * touches credits — even with billing enforced and no credits at all.
 */
class OwnAnthropicKeyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-ant-api03-workspace-own-key-AbCd';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.enforce' => true,
            'billing.stage_holds_usd.gap_analysis' => '1.00',
            'boschifai.claude.api_key' => 'sk-ant-platform-key-0000',
            'boschifai.claude.model' => 'claude-test-model',
        ]);
    }

    private function giveOwnKey(Workspace $workspace, string $status = WorkspaceAiCredential::STATUS_VALID): void
    {
        WorkspaceAiCredential::create(['workspace_id' => $workspace->id, 'provider' => ClaudeCredentialSource::Anthropic, 'secrets' => ['api_key' => self::KEY], 'secret_hint' => '…AbCd', 'status' => $status]);
    }

    private function makeRun(Workspace $workspace): Run
    {
        $repo = RepoConfig::create(['workspace_id' => $workspace->id, 'name' => 'r'.$workspace->id, 'display_name' => 'r', 'git_remote_path' => '/tmp/x', 'base_branch' => 'main']);

        return Run::create(['repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => 'x', 'state' => RunState::GapAnalysisRunning]);
    }

    public function test_runs_use_the_workspaces_own_key_and_everyone_else_the_platforms(): void
    {
        $this->giveOwnKey($this->workspace());
        $resolver = app(ClaudeCredentialResolver::class);

        $own = $resolver->resolveFor($this->makeRun($this->workspace()), 'gap_analysis');
        $this->assertSame(['ANTHROPIC_API_KEY' => self::KEY], $own->env);
        $this->assertSame([ClaudeBillingMode::CustomerAccount, ClaudeCredentialSource::Anthropic, 'claude-test-model'], [$own->billingMode, $own->source, $own->model]);
        $this->assertSame([self::KEY], $own->secretsToRedact);

        $platform = $resolver->resolveFor($this->makeRun(Workspace::create(['name' => 'Other'])), 'gap_analysis');
        $this->assertSame(['ANTHROPIC_API_KEY' => 'sk-ant-platform-key-0000'], $platform->env);
        $this->assertSame(ClaudeBillingMode::Platform, $platform->billingMode);
    }

    public function test_a_key_that_failed_its_last_check_is_still_used_rather_than_silently_switching_to_credits(): void
    {
        $this->giveOwnKey($this->workspace(), WorkspaceAiCredential::STATUS_INVALID);

        $credentials = app(ClaudeCredentialResolver::class)->resolveFor($this->makeRun($this->workspace()), 'gap_analysis');

        $this->assertSame(ClaudeCredentialSource::Anthropic, $credentials->source);
    }

    public function test_a_step_on_the_own_key_runs_without_credits_even_when_enforced(): void
    {
        $this->giveOwnKey($this->workspace());
        $workspace = Mockery::mock(RunWorkspaceManager::class);
        $workspace->shouldReceive('path')->andReturn(sys_get_temp_dir());
        $workspace->shouldReceive('statusPaths')->andReturn([]);
        $this->app->instance(RunWorkspaceManager::class, $workspace);
        $passed = null;
        $invoker = Mockery::mock(HeadlessClaudeInvoker::class)->makePartial();
        $invoker->shouldReceive('invoke')->andReturnUsing(function (...$args) use (&$passed) {
            $passed = $args[5];

            return new ClaudeInvocationResult(exitCode: 0, timedOut: false, rawTranscriptPath: '', durationMs: 5, totalCostUsd: 0.4, numTurns: 1, stopReason: null, stderr: '');
        });
        $this->app->instance(HeadlessClaudeInvoker::class, $invoker);

        $outcome = app(StepExecutionService::class)->run($this->makeRun($this->workspace()), 'gap_analysis', '/boschifai-review', 60);

        $this->assertTrue($outcome->claudeResult->succeeded());
        $this->assertInstanceOf(ResolvedClaudeCredentials::class, $passed);
        $this->assertSame(self::KEY, $passed->env['ANTHROPIC_API_KEY']);
        $this->assertSame(0, CreditLedgerEntry::count(), 'Nothing held or charged.');
        $this->assertSame(0, InvocationCharge::count(), 'Nothing priced — Anthropic bills them directly.');
        $invocation = ClaudeInvocation::sole();
        $this->assertSame([ClaudeBillingMode::CustomerAccount, ClaudeCredentialSource::Anthropic], [$invocation->billing_mode, $invocation->credential_source]);
        $this->assertSame('0.4000', $invocation->total_cost_usd, 'Usage is still recorded.');
    }

    public function test_the_credit_gate_never_refuses_a_workspace_on_its_own_key(): void
    {
        $gate = app(CreditGate::class);
        $this->giveOwnKey($this->workspace());

        $gate->assertCanStart($this->workspace(), 'gap_analysis');

        $this->expectException(InsufficientCredits::class);
        $gate->assertCanStart(Workspace::create(['name' => 'No credits']), 'gap_analysis');
    }
}
