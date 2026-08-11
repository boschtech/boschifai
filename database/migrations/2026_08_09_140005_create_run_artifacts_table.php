<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            // testability_review | test_plan | test_cases_md | test_cases_json | generated_test_php
            $table->string('kind');
            $table->string('relative_path'); // path within the worktree, e.g. .boschifai/testability_review_x.md
            $table->longText('content');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->index(['run_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_artifacts');
    }
};
