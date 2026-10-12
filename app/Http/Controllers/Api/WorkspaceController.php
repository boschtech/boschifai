<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\WorkspaceInvitation;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;

/** The current workspace: its members and pending invitations, and renaming it. */
class WorkspaceController extends Controller
{
    public function show(CurrentWorkspace $currentWorkspace)
    {
        $workspace = $currentWorkspace->get();

        return response()->json(['data' => [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'members' => $workspace->users()->orderBy('name')->get()->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
            ])->values(),
            'invitations' => WorkspaceInvitation::forWorkspace($workspace)->pending()->with('invitedBy')->latest()->get()
                ->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'invited_by' => $invitation->invitedBy?->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ])->values(),
        ]]);
    }

    public function update(Request $request, CurrentWorkspace $currentWorkspace)
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $workspace = $currentWorkspace->get();
        $from = $workspace->name;

        $workspace->update(['name' => trim($validated['name'])]);
        AuditEvent::record('workspace.renamed', $workspace, $request->user(), $workspace, ['from' => $from, 'to' => $workspace->name]);

        return $this->show($currentWorkspace);
    }
}
