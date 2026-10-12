<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\WorkspaceInvitation;
use App\Services\Workspaces\WorkspaceMembershipService;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceInvitationController extends Controller
{
    public function __construct(private WorkspaceMembershipService $memberships) {}

    public function store(Request $request, CurrentWorkspace $currentWorkspace)
    {
        $validated = $request->validate([
            // The address goes into an email header. Laravel's `email` rule rejects CR/LF since
            // 12.60 (GHSA-5vg9-5847-vvmq) but still accepts other control characters such as a
            // tab, so they're refused explicitly as well.
            'email' => ['required', 'string', 'not_regex:/[\x00-\x1F\x7F]/', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ]);

        $invitation = $this->memberships->invite($currentWorkspace->get(), $request->user(), $validated['email'], WorkspaceRole::from($validated['role']));

        return response()->json(['data' => [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]], 201);
    }

    public function destroy(Request $request, WorkspaceInvitation $invitation)
    {
        $this->memberships->revokeInvitation($invitation, $request->user());

        return response()->noContent();
    }
}
