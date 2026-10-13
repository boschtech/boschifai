<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class WorkspaceInvitationsTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $email, ?WorkspaceRole $role = null, ?Workspace $workspace = null): User
    {
        $user = User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'correct-horse-battery-9', 'email_verified_at' => now()]);
        if ($role) {
            ($workspace ?? $this->workspace())->addMember($user, $role);
        }

        return $user;
    }

    private function fromSpa(): static
    {
        return $this->withHeader('Referer', config('app.url'));
    }

    /** Invites someone as $actor and returns the plain token from the email that was sent. */
    private function invite(User $actor, string $email, string $role = 'member'): string
    {
        Notification::fake();
        $this->actingAs($actor)->postJson('/api/workspace/invitations', ['email' => $email, 'role' => $role])->assertCreated();

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, WorkspaceInvitationNotification::class, function ($notification, $channels, $notifiable) use ($email, &$url) {
            $url = $notification->toMail($notifiable)->actionUrl;

            return $notifiable->routes['mail'] === strtolower($email);
        });
        Auth::forgetGuards();

        return basename($url);
    }

    // ── Managing members ────────────────────────────────────────────────────────────────

    public function test_the_workspace_page_lists_members_and_pending_invitations(): void
    {
        $owner = $this->person('owner@example.com', WorkspaceRole::Owner);
        $this->person('dev@example.com', WorkspaceRole::Member);
        $this->invite($owner, 'new@example.com', 'admin');

        $this->actingAs($owner)->getJson('/api/workspace')
            ->assertOk()
            ->assertJsonPath('data.name', 'Test Workspace')
            ->assertJsonPath('data.members.0.email', 'dev@example.com')
            ->assertJsonPath('data.members.1', ['id' => $owner->id, 'name' => 'Owner', 'email' => 'owner@example.com', 'role' => 'owner'])
            ->assertJsonPath('data.invitations.0.email', 'new@example.com')
            ->assertJsonPath('data.invitations.0.role', 'admin')
            ->assertJsonPath('data.invitations.0.invited_by', 'Owner');
    }

    public function test_an_admin_renames_the_workspace(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);

        $this->actingAs($admin)->patchJson('/api/workspace', ['name' => '  Acme Rentals '])->assertOk()->assertJsonPath('data.name', 'Acme Rentals');

        $this->assertSame(['from' => 'Test Workspace', 'to' => 'Acme Rentals'], AuditEvent::where('action', 'workspace.renamed')->sole()->meta);
    }

    public function test_an_admin_changes_roles_and_removes_members(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $dev = $this->person('dev@example.com', WorkspaceRole::Member);
        $dev->switchWorkspace($this->workspace());
        $this->actingAs($admin);

        $this->patchJson("/api/workspace/members/{$dev->id}", ['role' => 'admin'])->assertNoContent();
        $this->assertSame(WorkspaceRole::Admin, $dev->roleIn($this->workspace()));

        $this->deleteJson("/api/workspace/members/{$dev->id}")->assertNoContent();
        $this->assertNull($dev->roleIn($this->workspace()));
        $this->assertNull($dev->fresh()->current_workspace_id);

        $this->assertSame(['member.role_changed', 'member.removed'], AuditEvent::orderBy('id')->pluck('action')->all());
        $this->assertSame(['from' => 'member', 'to' => 'admin'], AuditEvent::where('action', 'member.role_changed')->sole()->meta);
    }

    public function test_only_owners_can_grant_change_or_remove_the_owner_role(): void
    {
        $owner = $this->person('owner@example.com', WorkspaceRole::Owner);
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $dev = $this->person('dev@example.com', WorkspaceRole::Member);
        $this->actingAs($admin);

        $this->patchJson("/api/workspace/members/{$dev->id}", ['role' => 'owner'])->assertForbidden();
        $this->patchJson("/api/workspace/members/{$owner->id}", ['role' => 'member'])->assertForbidden();
        $this->deleteJson("/api/workspace/members/{$owner->id}")->assertForbidden();
        $this->postJson('/api/workspace/invitations', ['email' => 'boss@example.com', 'role' => 'owner'])->assertForbidden();

        $this->assertSame(WorkspaceRole::Owner, $owner->roleIn($this->workspace()));
        $this->assertSame(WorkspaceRole::Member, $dev->roleIn($this->workspace()));

        $this->actingAs($owner)->patchJson("/api/workspace/members/{$dev->id}", ['role' => 'owner'])->assertNoContent();
        $this->assertSame(WorkspaceRole::Owner, $dev->roleIn($this->workspace()));
    }

    public function test_the_last_owner_cannot_be_demoted_or_removed(): void
    {
        $owner = $this->person('owner@example.com', WorkspaceRole::Owner);
        $this->actingAs($owner);

        $this->patchJson("/api/workspace/members/{$owner->id}", ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role' => 'A workspace needs at least one owner. Make someone else an owner first.']);
        $this->deleteJson("/api/workspace/members/{$owner->id}")->assertUnprocessable();

        $second = $this->person('second@example.com', WorkspaceRole::Owner);
        $this->deleteJson("/api/workspace/members/{$second->id}")->assertNoContent();
        $this->assertSame(WorkspaceRole::Owner, $owner->roleIn($this->workspace()));
    }

    public function test_setting_the_same_role_again_changes_nothing(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $dev = $this->person('dev@example.com', WorkspaceRole::Member);

        $this->actingAs($admin)->patchJson("/api/workspace/members/{$dev->id}", ['role' => 'member'])->assertNoContent();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_acting_on_someone_outside_the_workspace_is_a_404_and_roles_are_validated(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $outsider = $this->person('outsider@example.com');
        $dev = $this->person('dev@example.com', WorkspaceRole::Member);
        $this->actingAs($admin);

        $this->patchJson("/api/workspace/members/{$outsider->id}", ['role' => 'admin'])->assertNotFound();
        $this->deleteJson("/api/workspace/members/{$outsider->id}")->assertNotFound();
        $this->patchJson("/api/workspace/members/{$dev->id}", ['role' => 'superuser'])->assertJsonValidationErrors('role');
    }

    // ── Inviting ────────────────────────────────────────────────────────────────────────

    public function test_inviting_emails_a_single_use_link_and_stores_only_a_hash_of_its_token(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);

        $token = $this->invite($admin, 'New.Person@Example.com', 'admin');

        $invitation = WorkspaceInvitation::sole();
        $this->assertSame('new.person@example.com', $invitation->email);
        $this->assertSame(WorkspaceRole::Admin, $invitation->role);
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotContains($token, array_map('strval', array_values($invitation->getAttributes())));
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertSame(['email' => 'new.person@example.com', 'role' => 'admin'], AuditEvent::where('action', 'member.invited')->sole()->meta);
    }

    public function test_inviting_again_replaces_the_earlier_link(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $first = $this->invite($admin, 'new@example.com');
        $second = $this->invite($admin, 'new@example.com');

        $this->assertSame(1, WorkspaceInvitation::count());
        $this->getJson("/api/invitations/{$first}")->assertNotFound();
        $this->getJson("/api/invitations/{$second}")->assertOk();
    }

    public function test_the_invitation_goes_out_through_the_configured_mailer(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'no-reply@boschifai.test']);
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);

        $this->actingAs($admin)->postJson('/api/workspace/invitations', ['email' => 'new@example.com', 'role' => 'member'])->assertCreated();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $email = $sent[0]->getOriginalMessage();
        $this->assertSame('new@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('no-reply@boschifai.test', $email->getFrom()[0]->getAddress());
        $this->assertSame("You're invited to Test Workspace on BoschifAI", $email->getSubject());
    }

    public function test_when_the_email_cannot_be_sent_nothing_is_saved_and_the_earlier_link_still_works(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $earlier = WorkspaceInvitation::create([
            'workspace_id' => $this->workspace()->id, 'email' => 'new@example.com', 'role' => WorkspaceRole::Member,
            'token_hash' => WorkspaceInvitation::hashToken('earlier-token'), 'invited_by_user_id' => $admin->id, 'expires_at' => now()->addDay(),
        ]);
        Mail::extend('refusing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('554 Message rejected: Email address is not verified.');
            }

            public function __toString(): string
            {
                return 'refusing';
            }
        });
        config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.default' => 'refusing']);

        $this->actingAs($admin)->postJson('/api/workspace/invitations', ['email' => 'new@example.com', 'role' => 'admin'])
            ->assertStatus(503)
            ->assertJsonPath('message', "We couldn't send the invitation email. Please try again in a few minutes.");

        $this->assertSame([$earlier->id], WorkspaceInvitation::pluck('id')->all());
        $this->assertFalse(AuditEvent::where('action', 'member.invited')->exists());
        $this->getJson('/api/invitations/earlier-token')->assertOk();
    }

    public function test_an_address_with_line_breaks_or_control_characters_is_refused(): void
    {
        Notification::fake();
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);

        foreach (["new@example.com\r\nBcc: victim@example.com", "new\n@example.com", "new\t@example.com"] as $email) {
            $this->actingAs($admin)->postJson('/api/workspace/invitations', ['email' => $email, 'role' => 'member'])
                ->assertJsonValidationErrors('email');
        }
        Notification::assertNothingSent();
        $this->assertSame(0, WorkspaceInvitation::count());

        // Surrounding whitespace (a trailing newline from a paste) is trimmed off before validation.
        $this->actingAs($admin)->postJson('/api/workspace/invitations', ['email' => "new@example.com\n", 'role' => 'member'])->assertCreated();
        $this->assertSame('new@example.com', WorkspaceInvitation::sole()->email);
    }

    public function test_an_existing_member_cannot_be_invited(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $this->person('dev@example.com', WorkspaceRole::Member);

        $this->actingAs($admin)->postJson('/api/workspace/invitations', ['email' => 'DEV@example.com', 'role' => 'member'])
            ->assertJsonValidationErrors(['email' => 'This person is already a member of the workspace.']);
    }

    public function test_an_invitation_can_be_revoked(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $token = $this->invite($admin, 'new@example.com');

        $this->actingAs($admin)->deleteJson('/api/workspace/invitations/'.WorkspaceInvitation::sole()->id)->assertNoContent();

        $this->getJson("/api/invitations/{$token}")->assertNotFound();
        $this->assertSame(['email' => 'new@example.com'], AuditEvent::where('action', 'member.invitation_revoked')->sole()->meta);
    }

    // ── Accepting ───────────────────────────────────────────────────────────────────────

    public function test_the_link_shows_who_invited_whom_to_what(): void
    {
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'new@example.com', 'admin');

        $this->getJson("/api/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.workspace', 'Test Workspace')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.invited_by', 'Admin')
            ->assertJsonPath('data.has_account', false)
            ->assertJsonPath('data.signed_in_as', null);
    }

    public function test_someone_new_creates_their_account_and_is_signed_in(): void
    {
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'new@example.com', 'admin');

        $this->fromSpa()->postJson("/api/invitations/{$token}/accept", [
            'name' => 'New Person', 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77',
        ])->assertOk()->assertJsonPath('data.workspace', 'Test Workspace');

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame('New Person', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-brand-new-pass-77', $user->password));
        $this->assertSame(WorkspaceRole::Admin, $user->roleIn($this->workspace()));
        $this->assertSame($this->workspace()->id, $user->current_workspace_id);
        $this->assertAuthenticatedAs($user, 'web');

        $this->fromSpa()->getJson('/api/me')->assertOk()->assertJsonPath('data.current_workspace.role', 'admin');
        $this->getJson("/api/invitations/{$token}")->assertNotFound(); // single use
        $this->assertSame('member.joined', AuditEvent::latest('id')->first()->action);
    }

    public function test_a_new_account_needs_a_name_and_a_strong_confirmed_password(): void
    {
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'new@example.com');

        $this->fromSpa()->postJson("/api/invitations/{$token}/accept", ['name' => '', 'password' => 'short1', 'password_confirmation' => 'nope'])
            ->assertJsonValidationErrors(['name', 'password']);

        $this->assertSame(0, User::where('email', 'new@example.com')->count());
        $this->assertNull(WorkspaceInvitation::sole()->accepted_at);
    }

    public function test_an_existing_account_sets_a_new_password_from_the_link_and_joins(): void
    {
        $existing = $this->person('dev@example.com');
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'dev@example.com');
        $this->getJson("/api/invitations/{$token}")->assertJsonPath('data.has_account', true);

        $this->fromSpa()->postJson("/api/invitations/{$token}/accept", [
            'name' => 'Ignored', 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77',
        ])->assertOk()->assertJsonPath('data.workspace', 'Test Workspace');

        $existing->refresh();
        $this->assertTrue(Hash::check('a-brand-new-pass-77', $existing->password));
        $this->assertSame('Dev', $existing->name);
        $this->assertSame(WorkspaceRole::Member, $existing->roleIn($this->workspace()));
        $this->assertAuthenticatedAs($existing, 'web');
        $this->assertSame(1, User::where('email', 'dev@example.com')->count());
        $this->assertTrue(AuditEvent::where('action', 'user.password_set_via_invitation')->where('actor_user_id', $existing->id)->exists());
        $this->getJson("/api/invitations/{$token}")->assertNotFound(); // single use
    }

    public function test_setting_a_password_from_the_link_signs_out_the_accounts_other_sessions(): void
    {
        config(['session.driver' => 'database']); // the test suite runs on the array driver
        $existing = $this->person('dev@example.com');
        $existing->forceFill(['remember_token' => 'old-remember-token'])->save();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $existing->id, 'payload' => '', 'last_activity' => time()]);
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'dev@example.com');

        $this->fromSpa()->postJson("/api/invitations/{$token}/accept", [
            'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77',
        ])->assertOk();

        $this->assertNotSame('old-remember-token', $existing->fresh()->remember_token);
        $this->assertFalse(DB::table('sessions')->where('id', 'other-device')->exists());
    }

    public function test_an_existing_account_needs_a_strong_confirmed_password_and_keeps_the_old_one_otherwise(): void
    {
        $existing = $this->person('dev@example.com');
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'dev@example.com');

        $this->fromSpa()->postJson("/api/invitations/{$token}/accept", ['password' => 'short1', 'password_confirmation' => 'nope'])
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check('correct-horse-battery-9', $existing->fresh()->password));
        $this->assertNull($existing->roleIn($this->workspace()));
        $this->assertNull(WorkspaceInvitation::sole()->accepted_at);
    }

    public function test_a_signed_in_user_joins_with_one_click_and_it_becomes_their_current_workspace(): void
    {
        $home = Workspace::create(['name' => 'Home']);
        $existing = $this->person('dev@example.com', WorkspaceRole::Owner, $home);
        $existing->switchWorkspace($home);
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'dev@example.com');

        $this->actingAs($existing)->getJson("/api/invitations/{$token}")->assertJsonPath('data.signed_in_as', 'dev@example.com');
        $this->actingAs($existing)->postJson("/api/invitations/{$token}/accept")->assertOk();

        $this->assertSame(WorkspaceRole::Member, $existing->roleIn($this->workspace()));
        $this->assertSame(WorkspaceRole::Owner, $existing->roleIn($home));
        $this->assertSame($this->workspace()->id, $existing->fresh()->current_workspace_id);
    }

    public function test_an_invitation_cannot_be_accepted_by_a_different_account(): void
    {
        $someoneElse = $this->person('else@example.com');
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'dev@example.com');

        $this->actingAs($someoneElse)->postJson("/api/invitations/{$token}/accept")
            ->assertJsonValidationErrors(['email' => 'This invitation was sent to dev@example.com. Sign in with that account to accept it.']);

        $this->assertNull($someoneElse->roleIn($this->workspace()));
    }

    public function test_expired_unknown_and_used_links_are_all_rejected_the_same_way(): void
    {
        $token = $this->invite($this->person('admin@example.com', WorkspaceRole::Admin), 'new@example.com');
        $message = 'This invitation link is no longer valid. Ask a workspace admin to send a new one.';

        $this->getJson('/api/invitations/'.str_repeat('x', 64))->assertNotFound()->assertJsonPath('message', $message);

        $this->travel(WorkspaceInvitation::LIFETIME_DAYS + 1)->days();
        $this->getJson("/api/invitations/{$token}")->assertNotFound()->assertJsonPath('message', $message);
        $this->fromSpa()->postJson("/api/invitations/{$token}/accept", ['name' => 'x', 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertNotFound();
        $this->assertSame(0, User::where('email', 'new@example.com')->count());
    }

    public function test_the_invitation_email_names_the_inviter_and_workspace_and_links_to_the_accept_page(): void
    {
        $admin = $this->person('admin@example.com', WorkspaceRole::Admin);
        $token = $this->invite($admin, 'new@example.com', 'admin');

        $mail = (new WorkspaceInvitationNotification(WorkspaceInvitation::sole(), $token))->toMail(new AnonymousNotifiable);

        $this->assertSame("You're invited to Test Workspace on BoschifAI", $mail->subject);
        $this->assertStringContainsString('Admin has invited you to join the "Test Workspace" workspace on BoschifAI as admin.', implode(' ', $mail->introLines));
        $this->assertSame(url("/invitations/{$token}"), $mail->actionUrl);
        $this->get("/invitations/{$token}")->assertOk()->assertViewIs('app');
    }

    public function test_audit_events_cannot_be_changed_or_deleted(): void
    {
        $event = AuditEvent::record('member.invited', $this->workspace(), null);

        try {
            $event->update(['action' => 'tampered']);
            $this->fail('An audit event was changed.');
        } catch (LogicException) {
        }
        try {
            $event->delete();
            $this->fail('An audit event was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame('member.invited', AuditEvent::sole()->action);
    }
}
