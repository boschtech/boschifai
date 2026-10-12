<?php

namespace Tests\Feature\Migrations;

use App\Support\Migrations\LegacyWorkspaceBackfill;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one-off move of pre-workspace data into a "Default" workspace. Runs against the schema as
 * it was right after the columns were added (constraints rolled back), with this test's own
 * in-memory database.
 */
class LegacyWorkspaceBackfillTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // Undo "enforce constraints" and "backfill" — workspace_id is nullable again.
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();
    }

    private function seedLegacyData(): void
    {
        $now = now();
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Dev', 'email' => 'dev@example.com', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('github_connections')->insert(['id' => 1, 'github_user_id' => 5, 'github_login' => 'acme', 'access_token' => 'x', 'token_type' => 'bearer', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('repo_configs')->insert(['id' => 1, 'name' => 'rams', 'display_name' => 'rams', 'git_remote_path' => '/r', 'base_branch' => 'main', 'github_connection_id' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('runs')->insert(['id' => 'run-1', 'repo_config_id' => 1, 'requirement_text' => 'x', 'target_file_path' => '', 'state' => 'failed', 'run_type' => 'requirement', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('run_steps')->insert(['id' => 1, 'run_id' => 'run-1', 'key' => 'gap_analysis', 'status' => 'failed', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('claude_invocations')->insert(['id' => 1, 'run_step_id' => 1, 'command' => '/x', 'prompt_text' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    }

    public function test_moves_everything_into_a_default_workspace_owned_by_every_existing_user(): void
    {
        $this->seedLegacyData();

        (new LegacyWorkspaceBackfill)();

        $workspace = DB::table('workspaces')->sole();
        $this->assertSame(['Default', 'default'], [$workspace->name, $workspace->slug]);

        foreach (['github_connections', 'repo_configs', 'runs', 'claude_invocations'] as $table) {
            $this->assertSame([$workspace->id], DB::table($table)->pluck('workspace_id')->unique()->values()->all(), $table);
        }

        $this->assertSame(
            [[1, 'owner'], [2, 'owner']],
            DB::table('workspace_user')->orderBy('user_id')->get()->map(fn ($m) => [$m->user_id, $m->role])->all(),
        );
        $this->assertSame([$workspace->id], DB::table('users')->pluck('current_workspace_id')->unique()->values()->all());

        // The constraints can now be enforced over the backfilled data.
        $this->artisan('migrate')->assertSuccessful();
    }

    public function test_does_nothing_on_a_fresh_install(): void
    {
        (new LegacyWorkspaceBackfill)();

        $this->assertSame(0, DB::table('workspaces')->count());
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        $this->seedLegacyData();

        (new LegacyWorkspaceBackfill)();
        (new LegacyWorkspaceBackfill)();

        $this->assertSame(1, DB::table('workspaces')->count());
        $this->assertSame(2, DB::table('workspace_user')->count());
    }
}
