<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GithubOAuthRedirectControllerTest extends TestCase
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

    public function test_redirects_straight_to_github_with_a_state_param(): void
    {
        $response = $this->get('/github/authorize');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        $this->assertStringContainsString('client_id=client-123', $location);
    }

    public function test_stores_a_single_use_state_nonce_the_callback_can_consume(): void
    {
        $location = $this->get('/github/authorize')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertTrue(Cache::has("github:oauth_state:{$query['state']}"));
    }

    public function test_422s_when_the_oauth_app_is_not_configured(): void
    {
        config(['boschifai.github.oauth_client_id' => null]);

        $this->get('/github/authorize')->assertStatus(422);
    }
}
