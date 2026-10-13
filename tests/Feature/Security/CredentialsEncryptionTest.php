<?php

namespace Tests\Feature\Security;

use App\Enums\ClaudeCredentialSource;
use App\Models\WorkspaceAiCredential;
use App\Support\Security\CredentialsEncrypter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/**
 * Workspaces' own AI keys are encrypted with a dedicated key (BOSCHIFAI_CREDENTIALS_KEY), not
 * APP_KEY, and that key can be rotated without anyone re-entering their key.
 */
class CredentialsEncryptionTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private const API_KEY = 'sk-ant-api03-workspace-own-key-AbCd';

    private function useKeys(?string $key, array $previous = []): void
    {
        config(['boschifai.claude.credentials_encryption' => ['key' => $key, 'previous_keys' => $previous]]);
        // The encrypter is a singleton; start fresh with the new keys.
        app()->forgetInstance(CredentialsEncrypter::class);
    }

    private function store(): void
    {
        WorkspaceAiCredential::create(['workspace_id' => $this->workspace()->id, 'provider' => ClaudeCredentialSource::Anthropic, 'secrets' => ['api_key' => self::API_KEY], 'secret_hint' => '…AbCd', 'status' => 'valid']);
    }

    private function storedCiphertext(): string
    {
        return (string) DB::table('workspace_ai_credentials')->value('secrets');
    }

    public function test_keys_are_encrypted_with_the_dedicated_key_not_app_key(): void
    {
        $this->store();

        $this->assertStringNotContainsString(self::API_KEY, $this->storedCiphertext());
        $this->assertSame(self::API_KEY, WorkspaceAiCredential::sole()->secrets['api_key']);

        $this->expectException(DecryptException::class);
        Crypt::decryptString($this->storedCiphertext());
    }

    public function test_rotating_keeps_stored_keys_readable_and_moves_them_to_the_new_key(): void
    {
        $old = config('boschifai.claude.credentials_encryption.key');
        $this->store();
        $new = CredentialsEncrypter::generateKey();

        // Mid-rotation: new key current, old key still accepted for reading.
        $this->useKeys($new, [$old]);
        $this->assertSame(self::API_KEY, WorkspaceAiCredential::sole()->secrets['api_key']);

        $this->artisan('boschifai:rotate-credentials-key')
            ->expectsOutput('Re-encrypted 1 stored credential(s) with the current key. The previous key can now be removed.')
            ->assertSuccessful();

        // Old key gone: still readable, so it really was re-encrypted with the new one.
        $this->useKeys($new);
        $this->assertSame(self::API_KEY, WorkspaceAiCredential::sole()->secrets['api_key']);

        // And the old key alone can no longer read it.
        $this->useKeys($old);
        $this->expectException(DecryptException::class);
        WorkspaceAiCredential::sole()->secrets;
    }

    public function test_without_the_key_nothing_is_stored_or_sent_to_anthropic(): void
    {
        Http::fake();
        $this->useKeys(null);
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported) {
            $reported[] = $e->getMessage();

            return false;
        });

        $this->putJson('/api/workspace/ai-credentials', ['api_key' => self::API_KEY])
            ->assertStatus(503)
            ->assertJsonPath('message', "Using your own API key isn't available on this server yet. Contact BoschifAI support.");

        Http::assertNothingSent();
        $this->assertSame(0, WorkspaceAiCredential::count());
        $this->assertSame(['A workspace tried to save its own AI key, but BOSCHIFAI_CREDENTIALS_KEY is not set.'], $reported);
    }

    public function test_reading_without_the_key_fails_clearly_instead_of_falling_back_to_app_key(): void
    {
        $this->store();
        $this->useKeys('');

        $this->expectExceptionMessage('BOSCHIFAI_CREDENTIALS_KEY is not set');
        WorkspaceAiCredential::sole()->secrets;
    }

    public function test_a_generated_key_is_a_working_aes_256_key(): void
    {
        Artisan::call('boschifai:generate-credentials-key');
        $key = strtok(Artisan::output(), "\n");

        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $key);
        $this->assertSame(32, strlen(base64_decode(substr($key, 7))));
        $this->useKeys($key);
        $encrypter = app(CredentialsEncrypter::class);
        $this->assertSame(['api_key' => 'x'], $encrypter->decrypt($encrypter->encrypt(['api_key' => 'x'])));
        $this->assertNotSame(CredentialsEncrypter::generateKey(), CredentialsEncrypter::generateKey());
    }
}
