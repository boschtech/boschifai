<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The external ID a workspace's cloud roles (Bedrock) must require when trusting BoschifAI.
 * Generated once and never changed — customers put it in their role's trust policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('ai_external_id', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['ai_external_id']);
            $table->dropColumn('ai_external_id');
        });
    }
};
