<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // 'requirement' (today's flow: a written requirement + known target_file_path) or
            // 'coverage' (a plain "increase coverage for X" instruction, no known target file
            // yet — the pipeline itself works out which file to target). Deliberately no
            // separate instruction column: requirement_text already holds free text end-to-end
            // (see RunGapAnalysisJob/BranchAndCommitService) and needs no schema change to also
            // hold a coverage instruction — only the UI label and the prompt built from it differ.
            $table->string('run_type')->default('requirement')->after('target_file_path');

            // {install_command, test_command} parsed from the codebase-analysis step's knowledge
            // base artifact (coverage mode only) — lets LocalTestRunner run a repo's own test
            // suite in whatever stack it turns out to be, instead of the hardcoded PHP command.
            $table->json('execution_recipe')->nullable()->after('run_type');
        });

        // ArtifactKind::GeneratedTestPhp ('generated_test_php') was renamed to GeneratedTest
        // ('generated_test') so coverage-mode runs can generate tests in any stack, not just
        // PHPUnit — any pre-existing rows need to move to the new value or they'd silently stop
        // matching RunResource/RunCodeGenerationJob's ArtifactKind::GeneratedTest lookups.
        DB::table('run_artifacts')
            ->where('kind', 'generated_test_php')
            ->update(['kind' => 'generated_test']);
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['run_type', 'execution_recipe']);
        });

        DB::table('run_artifacts')
            ->where('kind', 'generated_test')
            ->update(['kind' => 'generated_test_php']);
    }
};
