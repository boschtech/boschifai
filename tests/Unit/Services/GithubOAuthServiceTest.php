<?php

namespace Tests\Unit\Services;

use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Services\Github\GithubOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'boschifai.github.oauth_client_id' => 'client-123',
            'boschifai.github.oauth_client_secret' => 'secret-abc',
        ]);
    }

    public function test_authorize_url_includes_the_client_id_repo_scope_and_state(): void
    {
        $url = (new GithubOAuthService())->authorizeUrl('the-state-value');

        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        $this->assertStringContainsString('client_id=client-123', $url);
        $this->assertStringContainsString('scope=repo', $url);
        $this->assertStringContainsString('state=the-state-value', $url);
    }

    public function test_authorize_url_throws_a_clear_error_when_the_oauth_app_is_not_configured(): void
    {
        config(['boschifai.github.oauth_client_id' => null]);

        $this->expectExceptionMessage('GitHub OAuth App is not configured');

        (new GithubOAuthService())->authorizeUrl('x');
    }

    public function test_exchange_code_for_token_returns_the_access_token_on_success(): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_faketoken', 'token_type' => 'bearer', 'scope' => 'repo',
            ]),
        ]);

        $token = (new GithubOAuthService())->exchangeCodeForToken('the-code');

        $this->assertSame('gho_faketoken', $token['access_token']);
    }

    public function test_exchange_code_for_token_throws_when_github_returns_a_200_with_an_error_body(): void
    {
        // GitHub's real failure mode for a bad/expired code — HTTP 200, error only in the body.
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.',
            ]),
        ]);

        $this->expectExceptionMessage('The code passed is incorrect or expired.');

        (new GithubOAuthService())->exchangeCodeForToken('stale-code');
    }

    public function test_fetch_authenticated_user_returns_id_and_login(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response(['id' => 42, 'login' => 'octocat']),
        ]);

        $user = (new GithubOAuthService())->fetchAuthenticatedUser('gho_faketoken');

        $this->assertSame(42, $user['id']);
        $this->assertSame('octocat', $user['login']);
    }

    public function test_list_user_repositories_paginates_until_a_short_page(): void
    {
        Http::fake([
            'api.github.com/user/repos?affiliation=owner%2Ccollaborator%2Corganization_member&per_page=100&page=1' => Http::response(
                array_fill(0, 100, ['full_name' => 'octocat/repo', 'name' => 'repo', 'private' => true, 'default_branch' => 'main'])
            ),
            'api.github.com/user/repos?affiliation=owner%2Ccollaborator%2Corganization_member&per_page=100&page=2' => Http::response([
                ['full_name' => 'octocat/repo2', 'name' => 'repo2', 'private' => false, 'default_branch' => 'main'],
            ]),
        ]);

        $repos = (new GithubOAuthService())->listUserRepositories('gho_faketoken');

        $this->assertCount(101, $repos);
    }

    public function test_token_for_returns_the_connected_accounts_decrypted_token(): void
    {
        $connection = GithubConnection::create([
            'github_user_id' => 1, 'github_login' => 'octocat', 'access_token' => 'gho_realtoken',
        ]);
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'octocat/backend',
            'git_remote_path' => 'https://github.com/octocat/backend.git', 'base_branch' => 'main',
            'github_connection_id' => $connection->id,
        ]);

        $token = (new GithubOAuthService())->tokenFor($repo->fresh(['githubConnection']));

        $this->assertSame('gho_realtoken', $token);
        // Confirms the encrypted cast round-trips correctly on a fresh read from the DB, not
        // just that the in-memory model still holds the plaintext value from create().
        $this->assertSame('gho_realtoken', $connection->fresh()->access_token);
    }

    public function test_token_for_throws_a_clear_error_when_the_repo_has_no_github_connection(): void
    {
        $repo = RepoConfig::create([
            'name' => 'backend', 'display_name' => 'Backend',
            'git_remote_path' => '/tmp/does-not-matter', 'base_branch' => 'main',
        ]);

        $this->expectExceptionMessage('has no GitHub account connected');

        (new GithubOAuthService())->tokenFor($repo);
    }
}
