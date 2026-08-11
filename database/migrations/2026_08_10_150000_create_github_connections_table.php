<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_user_id')->unique();
            $table->string('github_login');
            // Encrypted at rest (see GithubConnection's 'encrypted' cast) — unlike a GitHub App
            // installation token, a classic OAuth App user-to-server token doesn't expire and
            // isn't re-mintable from anything else this app holds, so it's a genuine long-lived
            // secret rather than a short-lived, cache-only value.
            $table->text('access_token');
            $table->string('scopes')->nullable();
            $table->string('token_type')->default('bearer');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_connections');
    }
};
