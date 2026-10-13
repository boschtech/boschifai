<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WorkspaceAiCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/**
 * Settings → AI provider: a workspace's own Anthropic key is checked with Anthropic before it's
 * saved, stored encrypted, and never sent back.
 */
class WorkspaceAiCredentialsTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private const KEY = 'sk-ant-api03-workspace-own-key-AbCd';

    /** What the fake Anthropic answers next: an HTTP status, or null for "can't connect". */
    private ?int $anthropicStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['boschifai.claude.hardening.api_base_url' => 'https://api.anthropic.com']);
        // One fake for the whole test — a second Http::fake() would only add to the first.
        Http::fake(fn () => $this->anthropicStatus === null
            ? Http::failedConnection('timed out')
            : Http::response(['data' => []], $this->anthropicStatus));
    }

    private function anthropicAnswers(?int $status): void
    {
        $this->anthropicStatus = $status;
    }

    private function save(string $key = self::KEY)
    {
        return $this->putJson('/api/workspace/ai-credentials', ['api_key' => $key]);
    }

    public function test_a_workspace_starts_on_the_platform_key(): void
    {
        $this->getJson('/api/workspace/ai-credentials')->assertOk()->assertExactJson(['data' => ['provider' => 'platform']]);
    }

    public function test_a_key_anthropic_accepts_is_saved_encrypted_and_never_sent_back(): void
    {
        $this->anthropicAnswers(200);

        $response = $this->save()->assertOk()
            ->assertJsonPath('data.provider', 'anthropic')
            ->assertJsonPath('data.secret_hint', '…AbCd')
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.updated_by', $this->user->name);

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringNotContainsString(self::KEY, $this->getJson('/api/workspace/ai-credentials')->getContent());
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('workspace_ai_credentials')->value('secrets'), 'Stored encrypted.');
        $this->assertSame(self::KEY, WorkspaceAiCredential::sole()->secrets['api_key']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/models?limit=1'
            && $request->header('x-api-key') === [self::KEY]
            && $request->header('anthropic-version') === ['2023-06-01']);
        $this->assertSame(['provider' => 'anthropic', 'hint' => '…AbCd'], AuditEvent::where('action', 'ai_credentials.saved')->sole()->meta);
    }

    public function test_a_key_anthropic_rejects_is_not_saved(): void
    {
        $this->anthropicAnswers(401);

        $this->save()->assertJsonValidationErrors(['api_key' => 'Anthropic rejected this API key.']);

        $this->assertSame(0, WorkspaceAiCredential::count());
    }

    public function test_nothing_changes_when_anthropic_cannot_be_reached(): void
    {
        $this->anthropicAnswers(200);
        $this->save();

        $this->anthropicAnswers(529);
        $this->save('sk-ant-api03-a-different-key-WxYz')
            ->assertStatus(503)
            ->assertJsonPath('message', "Anthropic couldn't check the key right now (HTTP 529). Nothing was changed — try again in a moment.");

        $this->anthropicAnswers(null);
        $this->save('sk-ant-api03-a-different-key-WxYz')
            ->assertStatus(503)
            ->assertJsonPath('message', "Couldn't reach Anthropic to check the key. Nothing was changed — try again in a moment.");

        $this->assertSame(self::KEY, WorkspaceAiCredential::sole()->secrets['api_key']);
    }

    public function test_the_key_must_look_like_a_key(): void
    {

        $this->save('short')->assertJsonValidationErrors('api_key');
        $this->save('sk-ant-api03-has a space-in-it-AbCd')->assertJsonValidationErrors(['api_key' => 'The API key must not contain spaces or line breaks.']);
        $this->putJson('/api/workspace/ai-credentials', [])->assertJsonValidationErrors('api_key');

        Http::assertNothingSent();
    }

    public function test_the_stored_key_can_be_rechecked(): void
    {
        $this->anthropicAnswers(200);
        $this->save();

        $this->anthropicAnswers(401);
        $this->postJson('/api/workspace/ai-credentials/check')->assertOk()
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.validation_error', 'Anthropic rejected this API key. Check that it was copied in full and is still active in the Anthropic Console.');

        $this->anthropicAnswers(500);
        $this->postJson('/api/workspace/ai-credentials/check')->assertStatus(503);
        $this->assertSame('invalid', WorkspaceAiCredential::sole()->status, 'An unreachable Anthropic changes nothing.');

        $this->anthropicAnswers(200);
        $this->postJson('/api/workspace/ai-credentials/check')->assertOk()->assertJsonPath('data.status', 'valid')->assertJsonPath('data.validation_error', null);
    }

    public function test_there_is_nothing_to_check_on_the_platform_key(): void
    {
        $this->postJson('/api/workspace/ai-credentials/check')->assertNotFound();
    }

    public function test_removing_the_key_moves_the_workspace_back_to_the_platform(): void
    {
        $this->anthropicAnswers(200);
        $this->save();

        $this->deleteJson('/api/workspace/ai-credentials')->assertOk()->assertExactJson(['data' => ['provider' => 'platform']]);
        $this->deleteJson('/api/workspace/ai-credentials')->assertOk();

        $this->assertSame(0, WorkspaceAiCredential::count());
        $this->assertSame(['provider' => 'anthropic', 'hint' => '…AbCd'], AuditEvent::where('action', 'ai_credentials.removed')->sole()->meta);
    }

    public function test_billing_says_when_runs_use_the_workspaces_own_key(): void
    {
        $this->getJson('/api/billing')->assertJsonPath('data.uses_own_ai_key', false);

        $this->anthropicAnswers(200);
        $this->save();

        $this->getJson('/api/billing')->assertJsonPath('data.uses_own_ai_key', true);
    }

    public function test_members_cannot_see_or_change_the_key(): void
    {
        $member = User::factory()->create();
        $this->workspace()->addMember($member, WorkspaceRole::Member);
        $member->forceFill(['current_workspace_id' => $this->workspace()->id])->save();
        $this->actingAs($member);

        $this->getJson('/api/workspace/ai-credentials')->assertForbidden();
        $this->putJson('/api/workspace/ai-credentials', ['api_key' => self::KEY])->assertForbidden();
    }
}
