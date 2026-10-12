<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\Workspaces\WorkspaceMembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Opening and accepting an invitation link — the only way to get an account besides an admin
 * running `boschifai:create-user` (open self-registration stays off until billing exists, so
 * nobody can run AI work on the platform's key unasked).
 *
 * Someone new chooses a name and password and is signed in; the address is treated as verified
 * because the link was sent to it. Someone who already has an account must sign in first — an
 * invitation link never sets the password of an existing account.
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
            abort_if(User::where('email', $invitation->email)->exists(), 409, 'You already have an account. Sign in to accept this invitation.');

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ]);

            $user = DB::transaction(function () use ($invitation, $validated) {
                $user = User::create(['name' => $validated['name'], 'email' => $invitation->email, 'password' => $validated['password']]);
                $user->forceFill(['email_verified_at' => now()])->save();

                return $user;
            });

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $this->memberships->accept($invitation, $user);

        return response()->json(['data' => ['workspace' => $invitation->workspace->name]]);
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
