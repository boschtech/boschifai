<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('human_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('gate'); // gap_analysis | pre_push
            $table->string('decision'); // approved | rejected | changes_requested
            $table->string('approved_by')->nullable();
            $table->text('comment')->nullable();
            // sha256 of the artifact set at approval time — a later mismatch invalidates the approval,
            // the audit-defensibility mechanism this needs in a regulated environment (see plan §2).
            $table->char('approved_artifact_hash', 64)->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('human_approvals');
    }
};
