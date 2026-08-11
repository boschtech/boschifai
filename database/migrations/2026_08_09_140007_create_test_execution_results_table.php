<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_execution_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->unsignedInteger('total');
            $table->unsignedInteger('passed');
            $table->unsignedInteger('failed');
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('duration_ms');
            $table->string('junit_xml_path')->nullable();
            $table->json('failing_tests')->nullable(); // [{name, message}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_execution_results');
    }
};
