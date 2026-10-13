<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\Workspaces\WorkspaceMembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Opening and accepting an invitation link — the only way to get an account besides an admin
 * running `boschifai:create-user` (open self-registration stays off until billing exists, so
 * nobody can run AI work on the platform's key unasked).
 *
 * Someone new chooses a name and password and is signed in; the address is treated as verified
 * because the link was sent to it. Someone who already has an account can either sign in first or
 * set a new password here: like a password-reset email, the link only reaches whoever controls
 * the mailbox. Setting it signs out every other session and is written to the audit log.
 */
class InvitationAcceptController extends Controller
{
    public function __construct(private WorkspaceMembershipService $memberships) {}

    public function show(Request $request, string $token)
    {
        $invitation = $this->usable($token);
        $signedIn = $this->signedInUser($request);

        return response()->json(['data' => [
            'workspace' => $invitation->workspace->name,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'invited_by' => $invitation->invitedBy?->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'has_account' => User::where('email', $invitation->email)->exists(),
            'signed_in_as' => $signedIn?->email,
        ]]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = $this->usable($token);
        $user = $this->signedInUser($request);

        if (! $user) {
            $user = User::where('email', $invitation->email)->first();
            $user = $user ? $this->setPassword($request, $invitation, $user) : $this->createAccount($request, $invitation);

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $this->memberships->accept($invitation, $user);

        return response()->json(['data' => ['workspace' => $invitation->workspace->name]]);
    }

    private function createAccount(Request $request, WorkspaceInvitation $invitation): User
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        return DB::transaction(function () use ($invitation, $validated) {
            $user = User::create(['name' => $validated['name'], 'email' => $invitation->email, 'password' => $validated['password']]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });
    }

    /**
     * An existing account that isn't signed in. The new password replaces the old one, so every
     * other session and "remember me" cookie for the account stops working — as after a reset.
     */
    private function setPassword(Request $request, WorkspaceInvitation $invitation, User $user): User
    {
        $validated = $request->validate([
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        DB::transaction(function () use ($invitation, $user, $validated) {
            $user->forceFill([
                'password' => $validated['password'],
                'remember_token' => Str::random(60),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            AuditEvent::record('user.password_set_via_invitation', $invitation->workspace, $user, $user);
        });

        return $user;
    }

    /**
     * These routes don't require sign-in, so nothing has picked a guard yet: Sanctum's guard
     * reads the SPA's session cookie (and covers API-token use), with the plain session guard
     * as a fallback.
     */
    private function signedInUser(Request $request): ?User
    {
        return $request->user('sanctum') ?? $request->user('web');
    }

    /** Unknown, already-used and expired links all look the same. */
    private function usable(string $token): WorkspaceInvitation
    {
        return WorkspaceInvitation::findUsable($token)
            ?? abort(404, 'This invitation link is no longer valid. Ask a workspace admin to send a new one.');
    }
}
