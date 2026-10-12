<?php

namespace Tests\Feature\Api;

use App\Enums\RunState;
use App\Enums\WorkspaceRole;
use App\Models\ClaudeInvocation;
use App\Models\GithubConnection;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunStep;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/**
 * The signed-in user (owner of their own workspace) must never see or touch another workspace's
 * data. Route-driven, so every route added later is checked automatically.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private Workspace $other;

    /** @var array<string, int|string> another workspace's record for each route parameter */
    private array $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->other = Workspace::create(['name' => 'Other Customer']);
        $connection = GithubConnection::create([
            'workspace_id' => $this->other->id, 'github_user_id' => 99, 'github_login' => 'other', 'access_token' => 'gho_other',
        ]);
        $repo = RepoConfig::create([
            'workspace_id' => $this->other->id, 'name' => 'api', 'display_name' => 'other/api',
            'git_remote_path' => 'https://github.com/other/api.git', 'base_branch' => 'main', 'github_connection_id' => $connection->id,
        ]);
        $run = Run::create([
            'repo_config_id' => $repo->id, 'requirement_text' => 'Their secret requirement', 'target_file_path' => '',
            'state' => RunState::Failed,
        ]);
        $step = RunStep::create(['run_id' => $run->id, 'key' => 'gap_analysis', 'status' => 'failed']);
        ClaudeInvocation::create(['run_step_id' => $step->id, 'command' => '/x', 'prompt_text' => 'x', 'total_cost_usd' => 9.99, 'input_tokens' => 1000]);

        $theirMember = User::create(['name' => 'Theirs', 'email' => 'theirs@example.com', 'password' => 'correct-horse-battery-9']);
        $this->other->addMember($theirMember, WorkspaceRole::Member);
        $theirInvitation = WorkspaceInvitation::create(['workspace_id' => $this->other->id, 'email' => 'invitee@example.com', 'role' => WorkspaceRole::Member, 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay()]);

        $this->foreign = [
            'run' => $run->id, 'step' => $step->id, 'repoConfig' => $repo->id, 'connection' => $connection->id, 'workspace' => $this->other->id,
            'member' => $theirMember->id, 'invitation' => $theirInvitation->id,
        ];
    }

    public function test_every_route_taking_another_workspaces_record_returns_404(): void
    {
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $parameters = $route->parameterNames();
            if (! str_starts_with($route->uri(), 'api/') || ! array_intersect($parameters, array_keys($this->foreign))) {
                continue;
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $this->foreign[$m[1]] ?? 'x', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->json($method, '/'.$uri)->assertNotFound();
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(15, $checked);
        $this->assertSame(RunState::Failed, Run::sole()->state, 'Nothing about the other workspace changed');
        $this->assertSame(1, WorkspaceInvitation::count());
        $this->assertSame(WorkspaceRole::Member, User::where('email', 'theirs@example.com')->sole()->roleIn($this->other));
    }

    public function test_lists_and_totals_only_show_the_current_workspace(): void
    {
        $mine = RepoConfig::create(['workspace_id' => $this->workspace()->id, 'name' => 'api', 'display_name' => 'mine/api', 'git_remote_path' => 'https://github.com/mine/api.git', 'base_branch' => 'main']);
        Run::create(['repo_config_id' => $mine->id, 'requirement_text' => 'Mine', 'target_file_path' => '', 'state' => RunState::Draft]);

        $this->getJson('/api/runs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.requirement_summary', 'Mine');
        $this->getJson('/api/repo-configs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.display_name', 'mine/api');
        $this->getJson('/api/github/connections')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/usage/tokens-this-month')->assertOk()->assertJsonPath('total_cost_usd', 0)->assertJsonPath('total_tokens', 0);
        $this->getJson('/api/usage/tokens-this-month/details')->assertOk()->assertJsonCount(0, 'invocations');
    }

    public function test_a_run_cannot_be_started_on_another_workspaces_repository(): void
    {
        $this->postJson('/api/runs', ['repo_config_id' => $this->foreign['repoConfig'], 'requirement_text' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('repo_config_id');

        $this->assertSame(1, Run::count());
    }

    public function test_two_workspaces_can_each_connect_a_repository_with_the_same_name(): void
    {
        $mine = GithubConnection::create(['workspace_id' => $this->workspace()->id, 'github_user_id' => 7, 'github_login' => 'me', 'access_token' => 'gho_me']);

        $this->postJson("/api/github/connections/{$mine->id}/repositories", [
            'repositories' => [['full_name' => 'mine/api', 'default_branch' => 'main']],
        ])->assertOk();

        $this->assertSame(['other/api', 'mine/api'], RepoConfig::where('name', 'api')->orderBy('id')->pluck('display_name')->all());
    }

    public function test_the_same_github_account_can_be_connected_in_two_workspaces(): void
    {
        GithubConnection::create(['workspace_id' => $this->workspace()->id, 'github_user_id' => 99, 'github_login' => 'other', 'access_token' => 'gho_mine']);

        $this->assertSame(2, GithubConnection::where('github_user_id', 99)->count());
    }

    public function test_switching_to_a_workspace_you_are_not_in_is_a_404(): void
    {
        $this->postJson("/api/workspaces/{$this->other->id}/switch")->assertNotFound();
        $this->assertSame($this->workspace()->id, $this->user->fresh()->current_workspace_id);
    }

    public function test_records_are_attributed_to_their_workspace_automatically(): void
    {
        $run = Run::sole();

        $this->assertSame($this->other->id, $run->workspace_id);
        $this->assertSame($this->other->id, ClaudeInvocation::sole()->workspace_id);
    }

    public function test_a_user_in_no_workspace_cannot_use_the_app(): void
    {
        $loner = User::create(['name' => 'Loner', 'email' => 'loner@example.com', 'password' => 'correct-horse-battery-9']);

        $this->actingAs($loner)->getJson('/api/runs')
            ->assertForbidden()
            ->assertJsonPath('message', "Your account isn't part of any workspace yet. Ask a workspace admin to add you.");
    }
}
