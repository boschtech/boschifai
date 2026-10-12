<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records, per invocation, who paid (billing_mode), whose credentials ran it (credential_source)
 * and the model used — the inputs Phase 2 credits and Phase 3 customer credentials build on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claude_invocations', function (Blueprint $table) {
            $table->string('billing_mode', 32)->nullable()->after('timed_out');
            $table->string('credential_source', 32)->nullable()->after('billing_mode');
            $table->string('model', 100)->nullable()->after('credential_source');
        });

        // Everything so far ran on the platform key. The model wasn't recorded, so it stays null.
        DB::table('claude_invocations')->update(['billing_mode' => 'platform', 'credential_source' => 'platform']);
    }

    public function down(): void
    {
        Schema::table('claude_invocations', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'credential_source', 'model']);
        });
    }
};
