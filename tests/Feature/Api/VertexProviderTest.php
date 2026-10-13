<?php

namespace Tests\Feature\Api;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
use App\Enums\RunState;
use App\Models\CreditLedgerEntry;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\WorkspaceAiCredential;
use App\Services\ClaudeRunner\ClaudeProcessHardening;
use App\Services\ClaudeRunner\Credentials\ClaudeCredentialResolver;
use App\Services\ClaudeRunner\Credentials\GoogleServiceAccountKey;
use App\Services\ClaudeRunner\Credentials\VertexEndpoint;
use App\Services\ClaudeRunner\HeadlessClaudeInvoker;
use App\Services\Pipeline\StepExecutionService;
use App\Services\Sandbox\RunWorkspaceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/**
 * A workspace's own Google Vertex AI through a service-account key: checked end to end with
 * Google before saving, stored encrypted, written to an owner-only file for each step only.
 */
class VertexProviderTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private const MODEL = 'claude-sonnet-4-5@20250929';

    private string $privateKey;

    private string $publicKey;

    /** What the fake Google answers: [token endpoint status, Vertex status], or null for "can't connect". */
    private ?array $google = [200, 200];

    protected function setUp(): void
    {
        parent::setUp();
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $privateKey);
        $this->privateKey = $privateKey;
        $this->publicKey = openssl_pkey_get_details($pair)['key'];

        Http::fake(function (Request $request) {
            if ($this->google === null) {
                return Http::failedConnection('timed out');
            }
            [$tokenStatus, $vertexStatus] = $this->google;

            return str_starts_with($request->url(), GoogleServiceAccountKey::TOKEN_URI)
                ? Http::response($tokenStatus === 200 ? ['access_token' => 'ya29.short-lived-token', 'expires_in' => 3599] : ['error' => 'invalid_grant'], $tokenStatus)
                : Http::response(['content' => [['type' => 'text', 'text' => 'OK']]], $vertexStatus);
        });
    }

    private function keyJson(array $overrides = []): string
    {
        return json_encode([
            'type' => 'service_account', 'project_id' => 'acme-claude', 'private_key_id' => 'abcdef1234567890',
            'private_key' => $this->privateKey, 'client_email' => 'boschifai@acme-claude.iam.gserviceaccount.com',
            'client_id' => '1234', 'token_uri' => GoogleServiceAccountKey::TOKEN_URI, 'extra_field' => 'dropped', ...$overrides,
        ]);
    }

    private function save(array $overrides = [])
    {
        return $this->putJson('/api/workspace/ai-credentials', ['provider' => 'vertex', 'service_account_key' => $this->keyJson(), 'region' => 'us-east5', 'model' => self::MODEL, ...$overrides]);
    }

    private function makeRun(): Run
    {
        $repo = RepoConfig::create(['workspace_id' => $this->workspace()->id, 'name' => 'r', 'display_name' => 'r', 'git_remote_path' => '/tmp/x', 'base_branch' => 'main']);

        return Run::create(['repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => 'x', 'state' => RunState::GapAnalysisRunning]);
    }

    // ── Saving ─────────────────────────────────────────────────────────────────────────

    public function test_a_working_key_is_checked_with_google_then_saved_encrypted_and_never_shown(): void
    {
        $response = $this->save()->assertOk()
            ->assertJsonPath('data.provider', 'vertex')
            ->assertJsonPath('data.secret_hint', 'boschifai@acme-claud')
            ->assertJsonPath('data.config', ['project_id' => 'acme-claude', 'region' => 'us-east5', 'model' => self::MODEL, 'client_email' => 'boschifai@acme-claude.iam.gserviceaccount.com']);

        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', (string) DB::table('workspace_ai_credentials')->value('secrets'), 'Stored encrypted.');
        $stored = WorkspaceAiCredential::sole()->secrets['service_account'];
        $this->assertSame($this->privateKey, $stored['private_key']);
        $this->assertArrayNotHasKey('extra_field', $stored, 'Only the standard key fields are kept.');

        // The token request carries a JWT signed with the customer's key, for Google's token endpoint.
        Http::assertSent(function (Request $request) {
            if ($request->url() !== GoogleServiceAccountKey::TOKEN_URI) {
                return false;
            }
            [$header, $claims, $signature] = explode('.', $request['assertion']);
            $decode = fn ($part) => base64_decode(strtr($part, '-_', '+/'));
            $payload = json_decode($decode($claims), true);

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && openssl_verify("{$header}.{$claims}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256) === 1
                && $payload['iss'] === 'boschifai@acme-claude.iam.gserviceaccount.com'
                && $payload['aud'] === GoogleServiceAccountKey::TOKEN_URI
                && $payload['scope'] === 'https://www.googleapis.com/auth/cloud-platform'
                && $payload['exp'] - $payload['iat'] === 600;
        });
        // Then one 1-token request to the model, with the short-lived token.
        Http::assertSent(fn (Request $request) => $request->url() === 'https://us-east5-aiplatform.googleapis.com/v1/projects/acme-claude/locations/us-east5/publishers/anthropic/models/'.self::MODEL.':rawPredict'
            && $request->header('Authorization') === ['Bearer ya29.short-lived-token']
            && $request['max_tokens'] === 1);
    }

    public function test_the_project_can_be_set_separately_from_the_keys_own(): void
    {
        $this->save(['project_id' => 'acme-billing', 'region' => 'global'])->assertOk()->assertJsonPath('data.config.project_id', 'acme-billing');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://aiplatform.googleapis.com/v1/projects/acme-billing/locations/global/publishers/anthropic/models/'.self::MODEL.':rawPredict');
    }

    public function test_a_file_that_is_not_a_usable_google_key_is_refused_before_contacting_google(): void
    {
        $cases = [
            'not json' => ['hello', 'That isn\'t a JSON key file.'],
            'other credential type' => [$this->keyJson(['type' => 'authorized_user']), 'That is not a service-account key'],
            'no email' => [$this->keyJson(['client_email' => '']), 'The key file is missing "client_email".'],
            'broken private key' => [$this->keyJson(['private_key' => "-----BEGIN PRIVATE KEY-----\nnope\n-----END PRIVATE KEY-----"]), "The key file's private key can't be read."],
            'foreign token endpoint' => [$this->keyJson(['token_uri' => 'https://attacker.example/token']), 'Only keys for Google Cloud itself'],
            'foreign universe' => [$this->keyJson(['universe_domain' => 'example.com']), 'Only keys for Google Cloud itself'],
        ];
        foreach ($cases as $label => [$keyFile, $message]) {
            $this->save(['service_account_key' => $keyFile])->assertJsonValidationErrors(['service_account_key' => $message]);
        }
        $this->save(['region' => 'Europe', 'model' => 'gpt-4', 'project_id' => 'X'])->assertJsonValidationErrors(['region', 'model', 'project_id']);

        Http::assertNothingSent();
        $this->assertSame(0, WorkspaceAiCredential::count());
    }

    public function test_googles_refusals_are_explained_and_nothing_is_saved(): void
    {
        foreach ([
            [[400, 200], 'Google rejected this service-account key.'],
            [[200, 403], "The service account can't use Vertex AI in this project."],
            [[200, 404], "Vertex AI doesn't offer this model here."],
        ] as [$google, $message]) {
            $this->google = $google;
            $this->save()->assertJsonValidationErrors(['service_account_key' => $message]);
        }

        $this->assertSame(0, WorkspaceAiCredential::count());
    }

    public function test_nothing_changes_when_google_cannot_be_checked(): void
    {
        foreach ([
            [[500, 200], "Google couldn't check the key right now."],
            [[200, 429], 'Vertex AI refused the check for quota reasons.'],
            [[200, 503], "Vertex AI couldn't be checked right now (HTTP 503)."],
            [null, "Couldn't reach Google Cloud to check the setup."],
        ] as [$google, $message]) {
            $this->google = $google;
            $this->assertStringStartsWith($message, $this->save()->assertStatus(503)->json('message'));
        }

        $this->assertSame(0, WorkspaceAiCredential::count());
    }

    public function test_the_saved_setup_can_be_rechecked(): void
    {
        $this->save();

        $this->google = [200, 403];
        $this->postJson('/api/workspace/ai-credentials/check')->assertOk()->assertJsonPath('data.status', 'invalid');
    }

    // ── Running on it ──────────────────────────────────────────────────────────────────

    public function test_each_step_gets_the_key_in_an_owner_only_file_that_is_deleted_afterwards(): void
    {
        $this->save();
        $credentials = app(ClaudeCredentialResolver::class)->resolveFor($this->makeRun(), 'gap_analysis');

        $path = $credentials->env['GOOGLE_APPLICATION_CREDENTIALS'];
        $this->assertSame(['GOOGLE_APPLICATION_CREDENTIALS' => $path], $credentials->env, 'No ANTHROPIC_API_KEY at all.');
        $this->assertStringStartsWith(storage_path('app/private/ai-credentials/'), $path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame($this->privateKey, json_decode(File::get($path), true)['private_key']);
        $this->assertSame([self::MODEL, ClaudeBillingMode::CustomerAccount, ClaudeCredentialSource::Vertex], [$credentials->model, $credentials->billingMode, $credentials->source]);
        $this->assertSame([
            'CLAUDE_CODE_USE_VERTEX' => '1', 'GOOGLE_APPLICATION_CREDENTIALS' => $path, 'ANTHROPIC_VERTEX_PROJECT_ID' => 'acme-claude',
            'CLOUD_ML_REGION' => 'us-east5', 'ANTHROPIC_VERTEX_BASE_URL' => 'https://us-east5-aiplatform.googleapis.com/v1', 'ANTHROPIC_DEFAULT_HAIKU_MODEL' => self::MODEL,
        ], $credentials->pinnedEnv);
        // The private key is redacted line by line, with its id.
        $this->assertContains('abcdef1234567890', $credentials->secretsToRedact);
        $this->assertContains(explode("\n", trim($this->privateKey))[1], $credentials->secretsToRedact);

        $credentials->cleanup();
        $this->assertFileDoesNotExist($path);
    }

    public function test_the_key_file_is_deleted_even_when_the_step_crashes_and_no_credits_are_used(): void
    {
        config(['billing.enforce' => true, 'billing.stage_holds_usd.gap_analysis' => '1.00']);
        $this->save();
        $workspace = Mockery::mock(RunWorkspaceManager::class);
        $workspace->shouldReceive('path')->andReturn(sys_get_temp_dir());
        $workspace->shouldReceive('statusPaths')->andReturn([]);
        $this->app->instance(RunWorkspaceManager::class, $workspace);
        $seenPath = null;
        $invoker = Mockery::mock(HeadlessClaudeInvoker::class)->makePartial();
        $invoker->shouldReceive('invoke')->andReturnUsing(function (...$args) use (&$seenPath) {
            $seenPath = $args[5]->env['GOOGLE_APPLICATION_CREDENTIALS'];
            $this->assertFileExists($seenPath);

            throw new RuntimeException('worker lost');
        });
        $this->app->instance(HeadlessClaudeInvoker::class, $invoker);

        try {
            app(StepExecutionService::class)->run($this->makeRun(), 'gap_analysis', '/boschifai-review', 60);
            $this->fail('Expected the crash to propagate.');
        } catch (RuntimeException) {
        }

        $this->assertFileDoesNotExist($seenPath);
        $this->assertSame(0, CreditLedgerEntry::count());
    }

    public function test_endpoints_match_the_clis_own_defaults(): void
    {
        $this->assertSame('https://aiplatform.googleapis.com/v1', VertexEndpoint::baseUrl('global'));
        $this->assertSame('https://aiplatform.eu.rep.googleapis.com/v1', VertexEndpoint::baseUrl('eu'));
        $this->assertSame('https://aiplatform.us.rep.googleapis.com/v1', VertexEndpoint::baseUrl('us'));
        $this->assertSame('https://europe-west1-aiplatform.googleapis.com/v1', VertexEndpoint::baseUrl('europe-west1'));
    }

    public function test_a_repository_cannot_switch_provider_or_name_a_credential_command(): void
    {
        $arguments = (new ClaudeProcessHardening)->cliArguments();
        $settings = json_decode($arguments[array_search('--settings', $arguments, true) + 1], true);

        foreach (['CLAUDE_CODE_USE_BEDROCK', 'CLAUDE_CODE_USE_VERTEX', 'CLAUDE_CODE_USE_FOUNDRY', 'CLAUDE_CODE_USE_MANTLE', 'CLAUDE_CODE_USE_ANTHROPIC_AWS', 'CLAUDE_CODE_USE_ANTHROPIC_GOOGLE_CLOUD', 'CLAUDE_CODE_USE_GATEWAY'] as $switch) {
            $this->assertSame('0', $settings['env'][$switch], $switch);
        }
        foreach (['apiKeyHelper', 'awsAuthRefresh', 'awsCredentialExport', 'gcpAuthRefresh', 'otelHeadersHelper'] as $command) {
            $this->assertSame('', $settings[$command], $command);
        }
    }
}
