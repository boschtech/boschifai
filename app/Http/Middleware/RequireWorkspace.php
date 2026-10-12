<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;

/** Signed-in users must belong to a workspace to use the app. */
class RequireWorkspace
{
    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next)
    {
        abort_unless($this->currentWorkspace->get(), 403, "Your account isn't part of any workspace yet. Ask a workspace admin to add you.");

        return $next($request);
    }
}
