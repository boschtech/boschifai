<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('key'); // gap_analysis | test_plan | test_case_generation | code_generation | local_execution | push | ci_poll
            $table->string('status')->default('pending'); // pending|running|succeeded|failed|timed_out
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_steps');
    }
};
