<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repo_configs', function (Blueprint $table) {
            $table->foreignId('github_connection_id')->nullable()->after('id')
                ->constrained('github_connections')->nullOnDelete();
            $table->string('github_owner')->nullable()->after('git_remote_path');
        });
    }

    public function down(): void
    {
        Schema::table('repo_configs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_connection_id');
            $table->dropColumn('github_owner');
        });
    }
};
