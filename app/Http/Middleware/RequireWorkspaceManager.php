<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;

/** Managing repositories and GitHub connections (and deleting runs) is for admins and owners. */
class RequireWorkspaceManager
{
    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next)
    {
        abort_unless($this->currentWorkspace->role()?->canManageWorkspace(), 403, 'Only workspace admins and owners can do this.');

        return $next($request);
    }
}
