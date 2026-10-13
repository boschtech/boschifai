<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace's own AI provider credentials (Settings → AI provider). One row per workspace;
 * no row = the platform's key and prepaid credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            // Encrypted with APP_KEY (model cast). Never returned by the API.
            $table->text('secrets');
            // Non-secret settings (region, project…) for providers that need them.
            $table->json('config')->nullable();
            // e.g. "…AbCd", so people can tell which key is stored.
            $table->string('secret_hint', 20);
            $table->string('status', 20);
            $table->string('validation_error')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_ai_credentials');
    }
};
