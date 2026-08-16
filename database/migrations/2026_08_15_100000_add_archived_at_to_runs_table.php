<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // Orthogonal to `state` — a run can be archived regardless of what state it's in.
            // Lets a user set aside a run that's still mid-pipeline or awaiting a gate (so a
            // different run can use the same repo's shared checkout — see
            // RunController::store()'s own "one active run per repo" guard) without deleting it
            // or forcing it into a misleading terminal state like Cancelled/Rejected.
            $table->timestamp('archived_at')->nullable()->after('cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
