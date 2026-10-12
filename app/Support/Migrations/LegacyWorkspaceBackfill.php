<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;

/**
 * One-off backfill run by the `backfill_default_workspace` migration. Before workspaces, every
 * signed-in user could see everything, so the existing data and users all go into one "Default"
 * workspace and every existing user becomes an owner of it — nobody loses access they had.
 *
 * Written against the query builder (not models) so later model changes can't break it. Safe to
 * run on an empty database (creates nothing) and idempotent (rows that already have a workspace
 * are left alone).
 */
class LegacyWorkspaceBackfill
{
    public const NAME = 'Default';

    public const SLUG = 'default';

    private const TENANT_TABLES = ['github_connections', 'repo_configs', 'runs'];

    public function __invoke(): void
    {
        $hasData = DB::table('users')->exists()
            || collect(self::TENANT_TABLES)->contains(fn ($table) => DB::table($table)->exists());

        if (! $hasData) {
            return;
        }

        DB::transaction(function () {
            $workspaceId = DB::table('workspaces')->where('slug', self::SLUG)->value('id')
                ?? DB::table('workspaces')->insertGetId([
                    'name' => self::NAME, 'slug' => self::SLUG, 'created_at' => now(), 'updated_at' => now(),
                ]);

            foreach (self::TENANT_TABLES as $table) {
                DB::table($table)->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);
            }

            // An invocation belongs to the workspace of the run it was part of.
            DB::table('claude_invocations')->whereNull('workspace_id')->update([
                'workspace_id' => DB::raw('(select runs.workspace_id from run_steps'
                    .' inner join runs on runs.id = run_steps.run_id'
                    .' where run_steps.id = claude_invocations.run_step_id)'),
            ]);

            DB::table('users')->orderBy('id')->each(function ($user) use ($workspaceId) {
                DB::table('workspace_user')->insertOrIgnore([
                    'workspace_id' => $workspaceId, 'user_id' => $user->id, 'role' => 'owner',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
            DB::table('users')->whereNull('current_workspace_id')->update(['current_workspace_id' => $workspaceId]);
        });
    }
}
