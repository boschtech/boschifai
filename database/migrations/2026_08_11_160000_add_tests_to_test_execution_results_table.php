<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_execution_results', function (Blueprint $table) {
            // Every test's outcome, not just failures — [{name, status: passed|failed|skipped,
            // message}] — needed to render a full pass/fail list rather than only surfacing
            // what went wrong. Replaces failing_tests, which is now a strict subset of this.
            $table->json('tests')->nullable()->after('duration_ms');
            $table->dropColumn('failing_tests');
        });
    }

    public function down(): void
    {
        Schema::table('test_execution_results', function (Blueprint $table) {
            $table->json('failing_tests')->nullable();
            $table->dropColumn('tests');
        });
    }
};
