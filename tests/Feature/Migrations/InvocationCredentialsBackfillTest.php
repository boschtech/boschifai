<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InvocationCredentialsBackfillTest extends TestCase
{
    use DatabaseMigrations;

    public function test_earlier_invocations_are_recorded_as_platform_usage_with_an_unknown_model(): void
    {
        $later = collect(File::files(database_path('migrations')))
            ->filter(fn ($file) => $file->getFilename() >= '2026_10_15_100000_add_credentials_to_claude_invocations_table.php')
            ->count();
        $this->artisan('migrate:rollback', ['--step' => $later])->assertSuccessful();

        $now = now();
        $workspaceId = DB::table('workspaces')->insertGetId(['name' => 'Acme', 'slug' => 'acme', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('repo_configs')->insert(['id' => 1, 'workspace_id' => $workspaceId, 'name' => 'rams', 'display_name' => 'rams', 'git_remote_path' => '/r', 'base_branch' => 'main', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('runs')->insert(['id' => 'run-1', 'workspace_id' => $workspaceId, 'repo_config_id' => 1, 'requirement_text' => 'x', 'target_file_path' => '', 'state' => 'failed', 'run_type' => 'requirement', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('run_steps')->insert(['id' => 1, 'run_id' => 'run-1', 'key' => 'gap_analysis', 'status' => 'failed', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('claude_invocations')->insert(['id' => 1, 'workspace_id' => $workspaceId, 'run_step_id' => 1, 'command' => '/x', 'prompt_text' => 'x', 'created_at' => $now, 'updated_at' => $now]);

        $this->artisan('migrate')->assertSuccessful();

        $row = DB::table('claude_invocations')->sole();
        $this->assertSame(['platform', 'platform', null], [$row->billing_mode, $row->credential_source, $row->model]);
    }
}
