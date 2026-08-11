<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ci_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('workflow_name')->default('Sonar Scan');
            $table->string('check_run_id')->nullable();
            $table->string('conclusion')->nullable(); // success | failure | cancelled | null = pending
            $table->timestamp('polled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_check_results');
    }
};
