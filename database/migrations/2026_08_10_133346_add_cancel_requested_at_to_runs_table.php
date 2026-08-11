<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // Set by RunController::cancel() the instant a user clicks "Stop" — the currently
            // executing job (queue worker, possibly a different container) doesn't learn about
            // this via any direct signal; it polls this column itself (CancellationChecker)
            // and stops its own in-flight process cooperatively. Kept as an audit trail (never
            // cleared back to null) rather than a plain boolean.
            $table->timestamp('cancel_requested_at')->nullable()->after('rejection_comment');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
