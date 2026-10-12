<?php

use App\Support\Migrations\LegacyWorkspaceBackfill;
use Illuminate\Database\Migrations\Migration;

/** Step 2 of 3: put everything that existed before workspaces into a "Default" workspace. */
return new class extends Migration
{
    public function up(): void
    {
        (new LegacyWorkspaceBackfill)();
    }

    public function down(): void
    {
        // Nothing to undo: rolling back step 1 drops the workspace columns this filled in.
    }
};
