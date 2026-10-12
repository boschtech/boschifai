<?php

namespace Tests\Feature\Auth;

use App\Enums\WorkspaceRole;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-9';

    private function user(): User
    {
        $user = User::create([
            'name' => 'Ops Admin', 'email' => 'ops@example.com',
            'password' => self::PASSWORD, 'email_verified_at' => now(),
        ]);
        $this->workspace()->addMember($user, WorkspaceRole::Owner);

        return $user;
    }

    /** Requests from the SPA carry its origin; Sanctum only treats those as session (cookie) requests. */
    private function fromSpa(): static
    {
        return $this->withHeader('Referer', config('app.url'));
    }

    public function test_every_api_route_rejects_a_guest(): void
    {
        // The only API routes deliberately open to guests: opening and accepting an invitation link.
        $public = ['api/invitations/{token}', 'api/invitations/{token}/accept'];

        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/') && ! in_array($route->uri(), $public, true));

        // Guards against the check silently covering nothing.
        $this->assertGreaterThan(20, $apiRoutes->count());

        foreach ($apiRoutes as $route) {
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->json($method, '/'.$uri)->assertUnauthorized();
            }
        }
    }

    public function test_a_user_signs_in_sees_themselves_and_signs_out(): void
    {
        $this->user();

        $this->fromSpa()->postJson('/login', ['email' => 'OPS@example.com', 'password' => self::PASSWORD])
            ->assertOk();

        $this->fromSpa()->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ops@example.com')
            ->assertJsonPath('data.name', 'Ops Admin');

        $this->fromSpa()->postJson('/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_a_wrong_password_is_rejected_without_revealing_which_part_was_wrong(): void
    {
        $this->user();

        $this->fromSpa()->postJson('/login', ['email' => 'ops@example.com', 'password' => 'not-it'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest('web');
    }

    public function test_sign_in_attempts_are_rate_limited(): void
    {
        $this->user();

        foreach (range(1, 5) as $attempt) {
            $this->fromSpa()->postJson('/login', ['email' => 'ops@example.com', 'password' => 'not-it'])->assertUnprocessable();
        }

        $this->fromSpa()->postJson('/login', ['email' => 'ops@example.com', 'password' => self::PASSWORD])
            ->assertTooManyRequests();
    }

    public function test_self_registration_is_not_available(): void
    {
        // No registration route exists — only the SPA's GET catch-all matches the path.
        $this->postJson('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'whatever-123', 'password_confirmation' => 'whatever-123'])
            ->assertMethodNotAllowed();
        $this->assertSame(0, User::count());
    }

    public function test_a_password_reset_email_links_to_the_spa_reset_page_and_the_reset_works(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->fromSpa()->postJson('/forgot-password', ['email' => 'ops@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$token) {
            $token = $notification->token;
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, url('/reset-password/'.$token));
        });

        // The reset page itself is the SPA shell.
        $this->get('/reset-password/'.$token)->assertOk()->assertViewIs('app');

        $this->fromSpa()->postJson('/reset-password', [
            'token' => $token, 'email' => 'ops@example.com',
            'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-pass-77', $user->fresh()->password));
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $this->user();

        $known = $this->fromSpa()->postJson('/forgot-password', ['email' => 'ops@example.com']);
        $unknown = $this->fromSpa()->postJson('/forgot-password', ['email' => 'nobody@example.com']);
        $throttled = $this->fromSpa()->postJson('/forgot-password', ['email' => 'ops@example.com']); // within the broker's throttle

        foreach ([$known, $unknown, $throttled] as $response) {
            $response->assertOk()->assertExactJson(['message' => NeutralPasswordResetLinkResponse::MESSAGE]);
        }
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_a_new_password_must_be_at_least_12_characters_with_letters_and_numbers(): void
    {
        Notification::fake();
        $user = $this->user();
        $token = app('auth.password.broker')->createToken($user);

        foreach (['short-1', 'only-letters-here', '1234567890123'] as $weak) {
            $this->fromSpa()->postJson('/reset-password', [
                'token' => $token, 'email' => 'ops@example.com', 'password' => $weak, 'password_confirmation' => $weak,
            ])->assertJsonValidationErrors('password');
        }
    }

    public function test_a_signed_in_user_can_change_their_password_with_the_current_one(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->fromSpa()->putJson('/user/password', [
            'current_password' => 'wrong', 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77',
        ])->assertJsonValidationErrors('current_password');

        $this->fromSpa()->putJson('/user/password', [
            'current_password' => self::PASSWORD, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-pass-77', $user->fresh()->password));
    }

    public function test_connecting_github_requires_a_signed_in_user(): void
    {
        $this->get('/github/authorize')->assertRedirect('/login');
        $this->get('/github/callback?code=x&state=y')->assertRedirect('/login');
    }

    public function test_the_spa_shell_and_login_page_are_public(): void
    {
        $this->get('/login')->assertOk()->assertViewIs('app');
    }
}
