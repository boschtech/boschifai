<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 of 3 (add → backfill → enforce): nullable first so existing rows survive until the
 * backfill gives them a workspace.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['github_connections', 'repo_configs', 'runs', 'claude_invocations'];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
        }

        // Who did it, as a real account. The existing string columns (`created_by`,
        // `approved_by`) stay as a snapshot of the email at the time, so history survives a user
        // being deleted.
        Schema::table('runs', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
        Schema::table('human_approvals', function (Blueprint $table) {
            $table->foreignId('approved_by_user_id')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('human_approvals', fn (Blueprint $table) => $table->dropConstrainedForeignId('approved_by_user_id'));
        Schema::table('runs', fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by_user_id'));
        foreach (array_reverse(self::TENANT_TABLES) as $name) {
            // Any single-column workspace_id index goes first — SQLite won't drop a column that
            // still has one. Looked up by name because it differs by database (MySQL's is the
            // foreign key's own `…_foreign` index, or the `…_index` one re-added when the
            // constraints were rolled back).
            $plainIndexes = collect(Schema::getIndexes($name))
                ->filter(fn (array $index) => $index['columns'] === ['workspace_id'] && ! $index['primary'])
                ->pluck('name');
            Schema::table($name, function (Blueprint $table) use ($plainIndexes) {
                $table->dropForeign(['workspace_id']);
                foreach ($plainIndexes as $index) {
                    $table->dropIndex($index);
                }
                $table->dropColumn('workspace_id');
            });
        }
    }
};
