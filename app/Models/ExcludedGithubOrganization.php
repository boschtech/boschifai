<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExcludedGithubOrganization extends Model
{
    protected $fillable = [
        'github_connection_id',
        'organization_login',
    ];

    public function githubConnection(): BelongsTo
    {
        return $this->belongsTo(GithubConnection::class);
    }
}
