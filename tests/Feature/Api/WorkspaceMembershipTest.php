<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Enums\WorkspaceRole;
use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Workspace $workspace, WorkspaceRole $role, string $email = 'person@example.com'): User
    {
        $user = User::create(['name' => 'Person', 'email' => $email, 'password' => 'correct-horse-battery-9', 'email_verified_at' => now()]);
        $workspace->addMember($user, $role);

        return $user;
    }

    public function test_me_lists_the_users_workspaces_and_the_current_one_with_their_role(): void
    {
        $acme = $this->workspace();
        $beta = Workspace::create(['name' => 'Beta Agency']);
        $user = $this->memberOf($acme, WorkspaceRole::Member);
        $beta->addMember($user, WorkspaceRole::Admin);
        $user->switchWorkspace($beta);

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.current_workspace', ['id' => $beta->id, 'name' => 'Beta Agency', 'role' => 'admin', 'can_manage' => true])
            ->assertJsonPath('data.workspaces', [
                ['id' => $beta->id, 'name' => 'Beta Agency', 'role' => 'admin'],
                ['id' => $acme->id, 'name' => 'Test Workspace', 'role' => 'member'],
            ]);
    }

    public function test_switching_workspace_changes_what_the_user_sees(): void
    {
        $beta = Workspace::create(['name' => 'Beta Agency']);
        $user = $this->memberOf($this->workspace(), WorkspaceRole::Owner);
        $beta->addMember($user, WorkspaceRole::Member);
        RepoConfig::create(['workspace_id' => $beta->id, 'name' => 'beta-api', 'display_name' => 'beta/api', 'git_remote_path' => '/x', 'base_branch' => 'main']);
        $user->switchWorkspace($this->workspace());
        $this->actingAs($user);

        $this->getJson('/api/repo-configs')->assertJsonCount(0, 'data');

        $this->postJson("/api/workspaces/{$beta->id}/switch")
            ->assertOk()
            ->assertJsonPath('data.current_workspace.id', $beta->id)
            ->assertJsonPath('data.current_workspace.can_manage', false);

        $this->getJson('/api/repo-configs')->assertJsonCount(1, 'data')->assertJsonPath('data.0.display_name', 'beta/api');
        $this->assertSame($beta->id, $user->fresh()->current_workspace_id);
    }

    public function test_falls_back_to_another_workspace_when_removed_from_the_current_one(): void
    {
        $beta = Workspace::create(['name' => 'Beta Agency']);
        $user = $this->memberOf($this->workspace(), WorkspaceRole::Member);
        $user->switchWorkspace($beta); // stale: not (or no longer) a member of Beta

        $this->actingAs($user)->getJson('/api/me')->assertJsonPath('data.current_workspace.id', $this->workspace()->id);
        $this->assertSame($this->workspace()->id, $user->fresh()->current_workspace_id);
    }

    public function test_members_cannot_manage_repositories_github_or_delete_runs(): void
    {
        $this->actingAs($this->memberOf($this->workspace(), WorkspaceRole::Member));

        // Real records in the member's own workspace, so it's the role check that refuses —
        // not a 404 for a record that doesn't exist.
        $connection = GithubConnection::create(['workspace_id' => $this->workspace()->id, 'github_user_id' => 1, 'github_login' => 'x', 'access_token' => 'gho_x']);
        $repo = RepoConfig::create(['workspace_id' => $this->workspace()->id, 'name' => 'api', 'display_name' => 'x/api', 'git_remote_path' => '/x', 'base_branch' => 'main']);
        $run = Run::create(['repo_config_id' => $repo->id, 'requirement_text' => 'x', 'target_file_path' => '', 'state' => RunState::Draft]);
        $colleague = $this->memberOf($this->workspace(), WorkspaceRole::Member, 'colleague@example.com');
        $invitation = WorkspaceInvitation::create(['workspace_id' => $this->workspace()->id, 'email' => 'new@example.com', 'role' => WorkspaceRole::Member, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
        $ids = ['run' => $run->id, 'repoConfig' => $repo->id, 'connection' => $connection->id, 'organization' => 'acme', 'member' => $colleague->id, 'invitation' => $invitation->id];

        $manageRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('workspace.manage', $route->gatherMiddleware(), true));
        $this->assertGreaterThanOrEqual(9, $manageRoutes->count());

        foreach ($manageRoutes as $route) {
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $ids[$m[1]], $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->json($method, '/'.$uri)->assertForbidden();
            }
        }

        $this->assertSame(1, Run::count());
        $this->assertSame(1, RepoConfig::count());
        $this->assertSame(1, WorkspaceInvitation::count());
        $this->assertSame(WorkspaceRole::Member, $colleague->roleIn($this->workspace()));
    }

    public function test_members_can_do_the_day_to_day_work(): void
    {
        $this->actingAs($this->memberOf($this->workspace(), WorkspaceRole::Member));

        $this->getJson('/api/runs')->assertOk();
        $this->getJson('/api/repo-configs')->assertOk();
        $this->getJson('/api/usage/tokens-this-month')->assertOk();
    }

    public function test_admins_and_owners_can_manage_the_workspace(): void
    {
        foreach ([WorkspaceRole::Admin, WorkspaceRole::Owner] as $i => $role) {
            $this->actingAs($this->memberOf($this->workspace(), $role, "manager{$i}@example.com"));

            $this->getJson('/api/github/connections')->assertOk();
        }
    }

    public function test_workspace_slugs_are_unique(): void
    {
        $this->assertSame(['agency', 'agency-2', 'agency-3'], collect(range(1, 3))->map(fn () => Workspace::create(['name' => 'Agency'])->slug)->all());
        $this->assertSame('workspace', Workspace::create(['name' => '!!!'])->slug);
    }
}
