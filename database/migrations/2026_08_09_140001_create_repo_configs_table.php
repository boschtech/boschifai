<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. "rams" — MVP has exactly one row
            $table->string('display_name');
            $table->string('git_remote_path'); // local path or URL to mirror-clone from
            $table->string('base_branch')->default('prod');
            $table->string('docker_image')->nullable(); // e.g. "rams-app:latest", for local test execution
            // Files gitignored in the target repo that a fresh `git worktree` therefore never
            // carries, but that `composer install`/tooling still needs — e.g. RAMS's own
            // auth.json (GitHub OAuth token for a private Composer package). Confirmed as a
            // real, previously-undiscovered gap via a live Docker smoke test: `composer
            // install` failed cloning a private package over SSH inside the throwaway
            // container until this was copied in from the source checkout. Same class of
            // problem as `.claude/` not being git-tracked (see WorktreeManager's boschifai-init step)
            // — just a different fix, since `boschifai init` can't regenerate a credentials file.
            $table->json('copy_untracked_files')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_configs');
    }
};
