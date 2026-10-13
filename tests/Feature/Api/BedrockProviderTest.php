<?php

namespace Tests\Feature\Api;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Enums\RunState;
use App\Enums\RunStepStatus;
use App\Models\ClaudeInvocation;
use App\Models\CreditLedgerEntry;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\Workspace;
use App\Models\WorkspaceAiCredential;
use App\Services\ClaudeRunner\ClaudeInvocationResult;
use App\Services\ClaudeRunner\ClaudeProcessHardening;
use App\Services\ClaudeRunner\Credentials\BedrockAccessDenied;
use App\Services\ClaudeRunner\Credentials\BedrockRoleAssumer;
use App\Services\ClaudeRunner\Credentials\ClaudeCredentialResolver;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\RunWorkspaceManager;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sts\StsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Concerns\SignsInUser;
use Tests\Support\FakeAws;
use Tests\TestCase;

/**
 * A workspace's own Amazon Bedrock: BoschifAI assumes a role in the customer's AWS account (with
 * the workspace's external ID) for short-lived credentials — checked end to end before saving,
 * used per step, never billed in credits.
 */
class BedrockProviderTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private const ROLE = 'arn:aws:iam::123456789012:role/BoschifAIBedrock';

    private const MODEL = 'us.anthropic.claude-sonnet-4-6';

    private FakeAws $aws;

    protected function setUp(): void
    {
        parent::setUp();
        config(['boschifai.claude.bedrock.platform_principal_arn' => 'arn:aws:iam::999999999999:role/boschifai-worker']);
        $this->aws = (new FakeAws)->install($this->app);
    }

    private function save(array $overrides = [])
    {
        return $this->putJson('/api/workspace/ai-credentials', ['provider' => 'bedrock', 'role_arn' => self::ROLE, 'region' => 'eu-west-1', 'model' => self::MODEL, ...$overrides]);
    }

    private function saved(): WorkspaceAiCredential
    {
        return WorkspaceAiCredential::create([
            'workspace_id' => $this->workspace()->id, 'provider' => ClaudeCredentialSource::Bedrock, 'secrets' => [],
            'config' => ['role_arn' => self::ROLE, 'region' => 'eu-west-1', 'model' => self::MODEL], 'secret_hint' => 'BoschifAIBedrock', 'status' => 'valid',
        ]);
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create(['workspace_id' => $this->workspace()->id, 'name' => 'r', 'display_name' => 'r', 'git_remote_path' => '/tmp/x', 'base_branch' => 'main']);

        return Run::create(['repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => 'x', 'state' => RunState::GapAnalysisRunning]);
    }

    // ── Setting it up ──────────────────────────────────────────────────────────────────

    public function test_the_setup_shows_boschifais_aws_identity_and_a_stable_external_id(): void
    {
        $first = $this->getJson('/api/workspace/ai-credentials')->assertOk()->json('data.bedrock');

        $this->assertSame(['available' => true, 'principal_arn' => 'arn:aws:iam::999999999999:role/boschifai-worker'], array_diff_key($first, ['external_id' => 1]));
        $this->assertMatchesRegularExpression('/^boschifai-[a-z0-9]{32}$/', $first['external_id']);
        $this->assertSame($first['external_id'], $this->getJson('/api/workspace/ai-credentials')->json('data.bedrock.external_id'));
    }

    public function test_the_external_id_is_never_replaced_even_by_a_stale_copy_of_the_workspace(): void
    {
        $stale = Workspace::find($this->workspace()->id);
        $id = Workspace::find($this->workspace()->id)->aiExternalId();

        $this->assertNull($stale->ai_external_id, 'Loaded before the ID existed.');
        $this->assertSame($id, $stale->aiExternalId());
        $this->assertSame($id, Workspace::find($this->workspace()->id)->ai_external_id);
    }

    public function test_bedrock_is_not_offered_until_the_platform_identity_is_configured(): void
    {
        config(['boschifai.claude.bedrock.platform_principal_arn' => null]);

        $this->getJson('/api/workspace/ai-credentials')->assertJsonPath('data.bedrock', ['available' => false]);
        $this->save()->assertStatus(503)->assertJsonPath('message', "Amazon Bedrock isn't available on this server yet. Contact BoschifAI support.");
        $this->assertSame([], $this->aws->calls);
    }

    public function test_a_working_role_is_checked_end_to_end_then_saved_without_any_aws_keys(): void
    {
        $this->aws->sts(true)->bedrock(true);

        $this->save()->assertOk()
            ->assertJsonPath('data.provider', 'bedrock')
            ->assertJsonPath('data.secret_hint', 'BoschifAIBedrock')
            ->assertJsonPath('data.config', ['role_arn' => self::ROLE, 'region' => 'eu-west-1', 'model' => self::MODEL])
            ->assertJsonPath('data.status', 'valid');

        [[$assume, $params], [$invoke, $body]] = $this->aws->calls;
        $this->assertSame(['AssumeRole', self::ROLE, $this->workspace()->aiExternalId()], [$assume, $params['RoleArn'], $params['ExternalId']]);
        $this->assertSame(['InvokeModel', self::MODEL], [$invoke, $body['modelId']]);
        $this->assertSame(1, json_decode($body['body'], true)['max_tokens'], 'The check costs a single output token.');
        $this->assertSame([], WorkspaceAiCredential::sole()->secrets, 'No AWS keys are stored.');
    }

    public function test_a_role_boschifai_cannot_assume_is_not_saved_and_aws_details_are_not_shown(): void
    {
        $this->aws->sts('AccessDenied');

        $response = $this->save()->assertJsonValidationErrors(['role_arn' => "AWS didn't let BoschifAI assume this role."]);

        $this->assertStringNotContainsString('123456789012', json_encode($response->json('errors')), "AWS's own message isn't passed on.");
        $this->assertSame(0, WorkspaceAiCredential::count());
    }

    public function test_a_role_that_cannot_use_the_model_is_not_saved(): void
    {
        $this->aws->sts(true, true)->bedrock('AccessDeniedException', 'ValidationException');

        $this->save()->assertJsonValidationErrors(['role_arn' => "The role can't invoke this model."]);
        $this->save()->assertJsonValidationErrors(['role_arn' => "Bedrock doesn't recognise this model in this region."]);
        $this->assertSame(0, WorkspaceAiCredential::count());
    }

    public function test_nothing_changes_when_aws_cannot_be_checked(): void
    {
        $this->aws->sts(true)->bedrock('ThrottlingException');

        $this->save()->assertStatus(503)->assertJsonPath('message', "Bedrock couldn't be checked right now (ThrottlingException). Nothing was changed — try again in a moment.");
        $this->assertSame(0, WorkspaceAiCredential::count());
    }

    public function test_the_role_region_and_model_must_look_right(): void
    {
        $this->save(['role_arn' => 'BoschifAIBedrock', 'region' => 'Europe', 'model' => 'claude sonnet'])
            ->assertJsonValidationErrors(['role_arn', 'region', 'model']);
        $this->putJson('/api/workspace/ai-credentials', ['provider' => 'vertex'])->assertJsonValidationErrors('provider');

        $this->assertSame([], $this->aws->calls);
    }

    public function test_the_saved_setup_can_be_rechecked(): void
    {
        $this->saved();
        $this->aws->sts('AccessDenied', true)->bedrock(true);

        $this->postJson('/api/workspace/ai-credentials/check')->assertOk()->assertJsonPath('data.status', 'invalid');
        $this->postJson('/api/workspace/ai-credentials/check')->assertOk()->assertJsonPath('data.status', 'valid');
    }

    // ── Running on it ──────────────────────────────────────────────────────────────────

    public function test_each_step_runs_on_short_lived_credentials_with_the_endpoint_pinned(): void
    {
        $this->saved();
        $this->aws->sts(true);
        $run = $this->makeRun();

        $credentials = app(ClaudeCredentialResolver::class)->resolveFor($run, 'gap_analysis');

        $this->assertSame([
            'AWS_REGION' => 'eu-west-1', 'AWS_ACCESS_KEY_ID' => FakeAws::ACCESS_KEY,
            'AWS_SECRET_ACCESS_KEY' => FakeAws::SECRET, 'AWS_SESSION_TOKEN' => FakeAws::TOKEN,
        ], $credentials->env, 'No ANTHROPIC_API_KEY at all.');
        $this->assertSame([self::MODEL, ClaudeBillingMode::CustomerAccount, ClaudeCredentialSource::Bedrock], [$credentials->model, $credentials->billingMode, $credentials->source]);
        $this->assertEqualsCanonicalizing([FakeAws::ACCESS_KEY, FakeAws::SECRET, FakeAws::TOKEN], $credentials->secretsToRedact);
        $endpoint = 'https://bedrock-runtime.eu-west-1.amazonaws.com';
        $this->assertSame([
            'CLAUDE_CODE_USE_BEDROCK' => '1', 'AWS_REGION' => 'eu-west-1', 'ANTHROPIC_BEDROCK_BASE_URL' => $endpoint,
            'AWS_ENDPOINT_URL' => $endpoint, 'AWS_ENDPOINT_URL_BEDROCK_RUNTIME' => $endpoint, 'ANTHROPIC_DEFAULT_HAIKU_MODEL' => self::MODEL,
        ], $credentials->pinnedEnv);

        $params = $this->aws->calls[0][1];
        $this->assertSame([$this->workspace()->aiExternalId(), 3600], [$params['ExternalId'], $params['DurationSeconds']]);
        $this->assertSame("boschifai-{$this->workspace()->id}-run-{$run->id}", $params['RoleSessionName']);
    }

    public function test_the_pinned_bedrock_settings_override_anything_the_repo_sets(): void
    {
        $env = json_decode((new ClaudeProcessHardening)->cliArguments(['CLAUDE_CODE_USE_BEDROCK' => '1', 'ANTHROPIC_BEDROCK_BASE_URL' => 'https://bedrock-runtime.eu-west-1.amazonaws.com'])[3], true)['env'];

        $this->assertSame('1', $env['CLAUDE_CODE_USE_BEDROCK']);
        $this->assertSame('https://bedrock-runtime.eu-west-1.amazonaws.com', $env['ANTHROPIC_BEDROCK_BASE_URL']);
    }

    public function test_a_bedrock_step_uses_no_credits_and_a_refused_role_fails_the_step_with_why(): void
    {
        config(['billing.enforce' => true, 'billing.stage_holds_usd.gap_analysis' => '1.00']);
        $this->saved();
        $workspace = Mockery::mock(RunWorkspaceManager::class);
        $workspace->shouldReceive('path')->andReturn(sys_get_temp_dir());
        $workspace->shouldReceive('statusPaths')->andReturn([]);
        $this->app->instance(RunWorkspaceManager::class, $workspace);
        $invoker = Mockery::mock(HeadlessClaudeInvoker::class)->makePartial();
        $invoker->shouldReceive('invoke')->once()->andReturn(new ClaudeInvocationResult(exitCode: 0, timedOut: false, rawTranscriptPath: '', durationMs: 5, totalCostUsd: 0.3, numTurns: 1, stopReason: null, stderr: ''));
        $this->app->instance(HeadlessClaudeInvoker::class, $invoker);
        $this->aws->sts(true, 'AccessDenied');

        $ok = app(StepExecutionService::class)->run($run = $this->makeRun(), 'gap_analysis', '/boschifai-review', 60);
        $this->assertTrue($ok->claudeResult->succeeded());
        $this->assertSame(0, CreditLedgerEntry::count());
        $this->assertSame(ClaudeCredentialSource::Bedrock, ClaudeInvocation::sole()->credential_source);

        // AWS now refuses the role: the step fails with the reason, Claude never starts.
        $refused = app(StepExecutionService::class)->run($run, 'test_plan', '/boschifai-test-plan', 60);
        $this->assertFalse($refused->claudeResult->succeeded());
        $this->assertFalse($refused->halted);
        $this->assertStringStartsWith("AWS didn't let BoschifAI assume this role.", $refused->claudeResult->failureReason());
        $step = $run->steps()->where('key', 'test_plan')->sole();
        $this->assertSame(RunStepStatus::Failed, $step->status);
        $this->assertSame(1, ClaudeInvocation::count());
    }

    // ── The role assumer itself ────────────────────────────────────────────────────────

    public function test_session_names_and_durations_are_kept_within_what_aws_accepts(): void
    {
        $this->aws->sts(true, true);
        $roles = app(BedrockRoleAssumer::class);

        $roles->assume(self::ROLE, 'ext', 'eu-west-1', str_repeat('run id with spaces/', 10), 60);
        $roles->assume(self::ROLE, 'ext', 'eu-west-1', 'short', 99999);

        [$first, $second] = [$this->aws->calls[0][1], $this->aws->calls[1][1]];
        $this->assertSame(64, strlen($first['RoleSessionName']));
        $this->assertMatchesRegularExpression('/^[\w+=,.@-]+$/', $first['RoleSessionName']);
        $this->assertSame([900, 3600], [$first['DurationSeconds'], $second['DurationSeconds']]);
    }

    public function test_aws_refusals_become_plain_messages(): void
    {
        $roles = app(BedrockRoleAssumer::class);
        $this->aws->sts('ValidationError', 'RegionDisabledException', 'InternalFailure');

        foreach (['AWS rejected the role details.', 'AWS STS is not enabled in this region', "AWS couldn't provide credentials for this role right now (InternalFailure)."] as $expected) {
            try {
                $roles->assume(self::ROLE, 'ext', 'eu-west-1', 's', 900);
                $this->fail('Expected a refusal.');
            } catch (BedrockAccessDenied $e) {
                $this->assertStringStartsWith($expected, $e->getMessage());
            }
        }
    }

    public function test_an_empty_sts_answer_is_an_error(): void
    {
        $handler = new MockHandler([new Result(['Credentials' => []])]);
        $roles = new BedrockRoleAssumer(fn ($region) => new StsClient(['region' => $region, 'version' => '2011-06-15', 'credentials' => false, 'handler' => $handler]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AWS STS returned no credentials for the Bedrock role.');
        $roles->assume(self::ROLE, 'ext', 'eu-west-1', 's', 900);
    }
}
