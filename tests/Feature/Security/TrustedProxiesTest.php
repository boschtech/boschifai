<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
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

    public function test_oauth_redirect_uri_uses_https_when_the_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->assertSame('https://boschifai.example/github/callback', $this->redirectUriBehindProxy());
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => null]);

        $redirectUri = $this->redirectUriBehindProxy();

        $this->assertStringStartsWith('http://', $redirectUri);
        $this->assertStringNotContainsString('boschifai.example', $redirectUri);
    }

    private function redirectUriBehindProxy(): string
    {
        $location = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'boschifai.example',
            'X-Forwarded-Port' => '443',
        ])->get('/github/authorize')->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['redirect_uri'];
    }
}
