<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per GitHub account that has authorized the Boschifai OAuth App (login.oauth.authorize
 * flow — see GithubOAuthService). access_token is a long-lived, non-expiring user-to-server
 * token (unlike a GitHub App installation token, there's nothing to re-mint it from), so it's
 * encrypted at rest — the first such column in this codebase.
 */
class GithubConnection extends Model
{
    protected $fillable = [
        'github_user_id',
        'github_login',
        'access_token',
        'scopes',
        'token_type',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
        ];
    }

    public function repoConfigs(): HasMany
    {
        return $this->hasMany(RepoConfig::class);
    }

    public function excludedOrganizations(): HasMany
    {
        return $this->hasMany(ExcludedGithubOrganization::class);
    }
}
