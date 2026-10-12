<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;

/** Makes another of the user's workspaces their current one. */
class WorkspaceSwitchController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, CurrentWorkspace $currentWorkspace)
    {
        // A workspace the user isn't in is a 404, not a 403 — don't confirm it exists.
        abort_unless($request->user()->roleIn($workspace), 404);

        $request->user()->switchWorkspace($workspace);
        $currentWorkspace->forget();

        return app(MeController::class)($request, $currentWorkspace);
    }
}
