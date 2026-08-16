<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // Original filenames of any "attach supporting files from your machine" uploads
            // (see RunController::store()) — the files themselves live under
            // storage/app/boschifai-runs/{run_id}/attachments/, this column is just the
            // manifest RunGapAnalysisJob reads to know what to copy into the checkout.
            $table->json('attachment_filenames')->nullable()->after('target_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('attachment_filenames');
        });
    }
};
