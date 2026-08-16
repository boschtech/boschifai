<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_execution_results', function (Blueprint $table) {
            // Relative to the checkout's own `coverage-report/` directory (e.g.
            // `app/Http/Controllers/Accounting/AccountSearchSuggestionsController.php.html`) —
            // PHPUnit's `--coverage-html` output path for the run's target class. Null when no
            // report was generated (coverage-mode's dynamic recipe, or a coverage driver missing
            // in this environment) — see LocalTestRunner's own docblock.
            $table->string('coverage_report_path')->nullable()->after('junit_xml_path');
        });
    }

    public function down(): void
    {
        Schema::table('test_execution_results', function (Blueprint $table) {
            $table->dropColumn('coverage_report_path');
        });
    }
};
