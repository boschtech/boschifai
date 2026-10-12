<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 of 3: every tenant row now has a workspace, so require it — and make the old global
 * uniques per-workspace, so two customers can each connect a repo called "api" or the same
 * GitHub account.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['github_connections', 'repo_configs', 'runs', 'claude_invocations'];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
            });
        }

        Schema::table('github_connections', function (Blueprint $table) {
            $table->dropUnique('github_connections_github_user_id_unique');
            $table->unique(['workspace_id', 'github_user_id']);
        });

        Schema::table('repo_configs', function (Blueprint $table) {
            $table->dropUnique('repo_configs_name_unique');
            $table->unique(['workspace_id', 'name']);
        });

        Schema::table('runs', fn (Blueprint $table) => $table->index(['workspace_id', 'state']));
        Schema::table('claude_invocations', fn (Blueprint $table) => $table->index(['workspace_id', 'created_at']));
    }

    public function down(): void
    {
        $this->ensurePlainWorkspaceIndex('claude_invocations');
        Schema::table('claude_invocations', fn (Blueprint $table) => $table->dropIndex(['workspace_id', 'created_at']));

        $this->ensurePlainWorkspaceIndex('runs');
        Schema::table('runs', fn (Blueprint $table) => $table->dropIndex(['workspace_id', 'state']));

        $this->ensurePlainWorkspaceIndex('repo_configs');
        Schema::table('repo_configs', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'name']);
            $table->unique('name');
        });

        $this->ensurePlainWorkspaceIndex('github_connections');
        Schema::table('github_connections', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'github_user_id']);
            $table->unique('github_user_id');
        });

        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('workspace_id')->nullable()->change();
            });
        }
    }

    /**
     * MySQL lets the composite indexes above double as the index the workspace_id foreign key
     * needs (dropping the key's own index when they're added), so one must exist again before a
     * composite index is dropped. SQLite keeps its own, so only add it when it's missing.
     */
    private function ensurePlainWorkspaceIndex(string $table): void
    {
        if (! Schema::hasIndex($table, ['workspace_id'])) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('workspace_id'));
        }
    }
};
