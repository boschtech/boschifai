<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Delete this organisation" needs to be durable — GithubOAuthService::listUserRepositories
        // always returns everything the connected account can see, so without this the org and
        // its repos would just reappear on the next page load even after every RepoConfig row
        // for it was deleted. One row per (connection, org login) hides it from the picker.
        Schema::create('excluded_github_organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_connection_id')->constrained()->cascadeOnDelete();
            $table->string('organization_login');
            $table->timestamps();
            $table->unique(['github_connection_id', 'organization_login'], 'excluded_github_orgs_connection_login_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excluded_github_organizations');
    }
};
