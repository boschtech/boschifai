<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;

/** The signed-in user and their workspaces, for the SPA's auth store (resources/js/store/modules/auth.js). */
class MeController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $currentWorkspace)
    {
        $user = $request->user();
        $current = $currentWorkspace->get();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'current_workspace' => [
                'id' => $current->id,
                'name' => $current->name,
                'role' => $currentWorkspace->role()->value,
                'can_manage' => $currentWorkspace->role()->canManageWorkspace(),
            ],
            'workspaces' => $user->workspaces()->orderBy('name')->get()
                ->map(fn ($workspace) => ['id' => $workspace->id, 'name' => $workspace->name, 'role' => $workspace->pivot->role])
                ->values(),
            // Single-tenant features the SPA hides when they're off (see `boschifai.features`).
            'features' => [
                'local_repos' => (bool) config('boschifai.features.local_repos'),
            ],
        ]]);
    }
}
