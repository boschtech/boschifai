<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * The workspace the signed-in user is working in. It's stored on the user
 * (`current_workspace_id`), so it follows them between devices; if that's empty or no longer
 * valid (they were removed from it), their first remaining workspace is used and saved.
 *
 * Only meaningful during a request — background jobs get the workspace from the record they're
 * working on (e.g. `$run->workspace_id`), never from here.
 */
class CurrentWorkspace
{
    private ?int $resolvedForUserId = null;

    private ?Workspace $workspace = null;

    private ?WorkspaceRole $role = null;

    public function get(): ?Workspace
    {
        $this->resolve();

        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function role(): ?WorkspaceRole
    {
        $this->resolve();

        return $this->role;
    }

    /** Re-resolved whenever the signed-in user changes (or after a switch). */
    public function forget(): void
    {
        $this->resolvedForUserId = null;
    }

    private function resolve(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            $this->resolvedForUserId = null;
            $this->workspace = null;
            $this->role = null;

            return;
        }

        if ($this->resolvedForUserId === $user->id) {
            return;
        }

        $workspace = $user->workspaces()->whereKey($user->current_workspace_id)->first()
            ?? $user->workspaces()->orderBy('workspaces.id')->first();

        if ($workspace && $workspace->id !== $user->current_workspace_id) {
            $user->switchWorkspace($workspace);
        }

        $this->resolvedForUserId = $user->id;
        $this->workspace = $workspace;
        $this->role = $workspace ? WorkspaceRole::from($workspace->pivot->role) : null;
    }
}
