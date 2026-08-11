<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('repo_config_id')->constrained('repo_configs');
            $table->longText('requirement_text');
            $table->string('target_file_path');
            $table->string('state')->default('draft'); // see App\Enums\RunState
            $table->string('branch_name')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_url')->nullable();
            $table->string('head_sha')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->json('confidence_breakdown')->nullable();
            $table->unsignedTinyInteger('testability_score')->nullable();
            $table->string('test_case_verdict')->nullable(); // APPROVED | NEEDS_FIXES, from /boschifai-test-cases' own validator
            $table->string('generated_file_path')->nullable(); // e.g. tests/Feature/CustomFieldControllerTest.php
            $table->foreignUuid('previous_run_id')->nullable()->constrained('runs');
            $table->text('blocked_reason')->nullable();
            $table->string('failed_step')->nullable();
            $table->text('error_message')->nullable();
            $table->string('rejection_comment')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
