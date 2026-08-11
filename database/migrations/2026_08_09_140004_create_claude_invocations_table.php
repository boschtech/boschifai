<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_invocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_step_id')->constrained('run_steps')->cascadeOnDelete();
            $table->string('command'); // e.g. "/boschifai-review"
            $table->longText('prompt_text');
            $table->string('raw_transcript_path')->nullable(); // storage/app/boschifai-runs/<run>/claude/<step>.jsonl
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('total_cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('num_turns')->nullable();
            $table->string('stop_reason')->nullable();
            $table->boolean('timed_out')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_invocations');
    }
};
