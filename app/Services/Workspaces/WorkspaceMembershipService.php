<?php

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Every change to who is in a workspace, and with which role, goes through here so the rules hold
 * everywhere:
 * - only admins and owners manage members;
 * - only owners can grant the owner role, or change or remove an owner;
 * - a workspace always keeps at least one owner;
 * - every change is written to the audit log.
 */
class WorkspaceMembershipService
{
    public function invite(Workspace $workspace, User $actor, string $email, WorkspaceRole $role): WorkspaceInvitation
    {
        $email = Str::lower(trim($email));
        $this->authorize($workspace, $actor, newRole: $role);

        if ($workspace->users()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already a member of the workspace.']);
        }

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($workspace, $actor, $email, $role, $token) {
            // A new invitation replaces any earlier one still waiting for this address.
            WorkspaceInvitation::forWorkspace($workspace)->pending()->where('email', $email)->delete();

            $invitation = WorkspaceInvitation::create([
                'workspace_id' => $workspace->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => WorkspaceInvitation::hashToken($token),
                'invited_by_user_id' => $actor->id,
                'expires_at' => now()->addDays(WorkspaceInvitation::LIFETIME_DAYS),
            ]);
            AuditEvent::record('member.invited', $workspace, $actor, $invitation, ['email' => $email, 'role' => $role->value]);

            // Sent inside the transaction: if the mail server refuses it, nothing is saved — no
            // invitation nobody received, and an earlier link for this address keeps working.
            try {
                Notification::route('mail', $email)->notify(new WorkspaceInvitationNotification($invitation, $token));
            } catch (TransportExceptionInterface $e) {
                report($e);

                throw new ServiceUnavailableHttpException(null, "We couldn't send the invitation email. Please try again in a few minutes.", $e);
            }

            return $invitation;
        });

        return $invitation;
    }

    public function revokeInvitation(WorkspaceInvitation $invitation, User $actor): void
    {
        $this->authorize($invitation->workspace, $actor, newRole: $invitation->role);

        $invitation->delete();
        AuditEvent::record('member.invitation_revoked', $invitation->workspace, $actor, null, ['email' => $invitation->email]);
    }

    public function changeRole(Workspace $workspace, User $actor, User $member, WorkspaceRole $role): void
    {
        $current = $member->roleIn($workspace);
        $this->authorize($workspace, $actor, $current, $role);

        if ($current === $role) {
            return;
        }

        $this->keepAnOwner($workspace, $current, function () use ($workspace, $member, $role) {
            $workspace->users()->updateExistingPivot($member->id, ['role' => $role->value]);
        });
        AuditEvent::record('member.role_changed', $workspace, $actor, $member, ['from' => $current->value, 'to' => $role->value]);
    }

    public function removeMember(Workspace $workspace, User $actor, User $member): void
    {
        $current = $member->roleIn($workspace);
        $this->authorize($workspace, $actor, $current);

        $this->keepAnOwner($workspace, $current, function () use ($workspace, $member) {
            $workspace->users()->detach($member->id);
            if ($member->current_workspace_id === $workspace->id) {
                // Their next request falls back to another workspace they belong to (if any).
                $member->forceFill(['current_workspace_id' => null])->save();
            }
        });
        AuditEvent::record('member.removed', $workspace, $actor, $member, ['email' => $member->email, 'role' => $current->value]);
    }

    /** Joins the workspace the invitation is for, as the invited role, and makes it current. */
    public function accept(WorkspaceInvitation $invitation, User $user): void
    {
        if (Str::lower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages(['email' => "This invitation was sent to {$invitation->email}. Sign in with that account to accept it."]);
        }

        DB::transaction(function () use ($invitation, $user) {
            $workspace = $invitation->workspace;
            if (! $user->roleIn($workspace)) {
                $workspace->addMember($user, $invitation->role);
            }
            $invitation->update(['accepted_at' => now(), 'accepted_by_user_id' => $user->id]);
            $user->switchWorkspace($workspace);
            AuditEvent::record('member.joined', $workspace, $user, $user, ['role' => $invitation->role->value]);
        });
    }

    /**
     * @param  WorkspaceRole|null  $targetRole  the member's current role (when acting on a member)
     * @param  WorkspaceRole|null  $newRole  the role being granted (invite or role change)
     */
    private function authorize(Workspace $workspace, User $actor, ?WorkspaceRole $targetRole = null, ?WorkspaceRole $newRole = null): void
    {
        $actorRole = $actor->roleIn($workspace);

        abort_unless($actorRole?->canManageWorkspace(), 403, 'Only workspace admins and owners can manage members.');
        abort_if(
            ($targetRole === WorkspaceRole::Owner || $newRole === WorkspaceRole::Owner) && $actorRole !== WorkspaceRole::Owner,
            403,
            'Only an owner can grant the owner role, or change or remove an owner.'
        );
    }

    /** Runs the change only if, when it removes an owner, another owner remains. */
    private function keepAnOwner(Workspace $workspace, ?WorkspaceRole $currentRole, callable $change): void
    {
        DB::transaction(function () use ($workspace, $currentRole, $change) {
            if ($currentRole === WorkspaceRole::Owner) {
                $owners = $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->lockForUpdate()->count();
                if ($owners <= 1) {
                    throw ValidationException::withMessages(['role' => 'A workspace needs at least one owner. Make someone else an owner first.']);
                }
            }

            $change();
        });
    }
}
