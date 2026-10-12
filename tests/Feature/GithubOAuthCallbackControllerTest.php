<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\GithubConnection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

class GithubOAuthCallbackControllerTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'boschifai.github.oauth_client_id' => 'client-123',
            'boschifai.github.oauth_client_secret' => 'secret-abc',
        ]);
    }

    /** What GithubOAuthRedirectController stores: who started the connection, for which workspace. */
    private function pendingState(string $state, ?int $userId = null, ?int $workspaceId = null): void
    {
        Cache::put("github:oauth_state:{$state}", [
            'user_id' => $userId ?? $this->user->id,
            'workspace_id' => $workspaceId ?? $this->workspace()->id,
        ], now()->addMinutes(10));
    }

    public function test_a_valid_single_use_state_and_code_creates_the_connection_and_redirects(): void
    {
        $this->pendingState('validstate');
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_faketoken', 'token_type' => 'bearer', 'scope' => 'repo',
            ]),
            'api.github.com/user' => Http::response(['id' => 42, 'login' => 'octocat']),
        ]);

        $response = $this->get('/github/callback?state=validstate&code=the-code');

        $connection = GithubConnection::where('github_user_id', 42)->firstOrFail();
        $this->assertSame($this->workspace()->id, $connection->workspace_id);
        $response->assertRedirect("/settings/github?connection={$connection->id}");
        $this->assertSame('octocat', $connection->github_login);
        $this->assertSame('gho_faketoken', $connection->access_token);
        $this->assertSame('repo', $connection->scopes);
        // Single-use: the nonce must be consumed, not merely checked.
        $this->assertFalse(Cache::has('github:oauth_state:validstate'));
    }

    public function test_the_access_token_is_stored_encrypted_at_rest(): void
    {
        $this->pendingState('validstate');
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_realtoken', 'token_type' => 'bearer', 'scope' => 'repo']),
            'api.github.com/user' => Http::response(['id' => 42, 'login' => 'octocat']),
        ]);

        $this->get('/github/callback?state=validstate&code=the-code');

        $rawColumnValue = DB::table('github_connections')->where('github_user_id', 42)->value('access_token');
        $this->assertStringNotContainsString('gho_realtoken', $rawColumnValue);
    }

    public function test_a_missing_state_is_rejected(): void
    {
        $this->get('/github/callback?code=the-code')->assertStatus(419);
        $this->assertDatabaseCount('github_connections', 0);
    }

    public function test_an_already_consumed_state_is_rejected_on_reuse(): void
    {
        $this->pendingState('reused');
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_faketoken', 'token_type' => 'bearer', 'scope' => 'repo']),
            'api.github.com/user' => Http::response(['id' => 42, 'login' => 'octocat']),
        ]);

        $this->get('/github/callback?state=reused&code=the-code')->assertRedirect();
        $this->get('/github/callback?state=reused&code=the-code')->assertStatus(419);
    }

    public function test_the_user_denying_authorization_redirects_with_a_friendly_message_not_an_error(): void
    {
        $this->pendingState('validstate');

        $response = $this->get('/github/callback?state=validstate&error=access_denied&error_description=The+user+has+denied+your+application+access.');

        $response->assertRedirect('/settings/github?error=The+user+has+denied+your+application+access.');
        $this->assertDatabaseCount('github_connections', 0);
    }

    public function test_a_link_started_by_someone_else_is_rejected(): void
    {
        $this->pendingState('someone-elses', userId: $this->user->id + 1);

        $this->get('/github/callback?state=someone-elses&code=abc')->assertStatus(419);
        $this->assertSame(0, GithubConnection::count());
    }

    public function test_the_connection_is_refused_if_the_user_no_longer_manages_that_workspace(): void
    {
        $other = Workspace::create(['name' => 'Other']);
        $other->addMember($this->user, WorkspaceRole::Member);
        $this->pendingState('demoted', workspaceId: $other->id);

        $this->get('/github/callback?state=demoted&code=abc')->assertForbidden();
        $this->assertSame(0, GithubConnection::count());
    }
}
