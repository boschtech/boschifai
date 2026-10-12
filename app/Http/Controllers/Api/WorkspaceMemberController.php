<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Workspaces\WorkspaceMembershipService;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceMemberController extends Controller
{
    public function __construct(private CurrentWorkspace $currentWorkspace, private WorkspaceMembershipService $memberships) {}

    public function update(Request $request, int $member)
    {
        // Looked up before validating, so someone outside the workspace is a 404 like everywhere else.
        $target = $this->member($member);
        $validated = $request->validate(['role' => ['required', Rule::enum(WorkspaceRole::class)]]);

        $this->memberships->changeRole($this->currentWorkspace->get(), $request->user(), $target, WorkspaceRole::from($validated['role']));

        return response()->noContent();
    }

    public function destroy(Request $request, int $member)
    {
        $this->memberships->removeMember($this->currentWorkspace->get(), $request->user(), $this->member($member));

        return response()->noContent();
    }

    /** Only members of the current workspace — anyone else is a 404. */
    private function member(int $id): User
    {
        return $this->currentWorkspace->get()->users()->whereKey($id)->firstOrFail();
    }
}
