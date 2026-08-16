<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local test execution no longer runs in Docker (see LocalTestRunner) — every
        // connected repo's tests now run directly via the worker's own PHP/Composer, so there's
        // no per-repo test-runner image to configure.
        Schema::table('repo_configs', function (Blueprint $table) {
            $table->dropColumn('docker_image');
        });
    }

    public function down(): void
    {
        Schema::table('repo_configs', function (Blueprint $table) {
            $table->string('docker_image')->nullable();
        });
    }
};
