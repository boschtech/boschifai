<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepoConfig extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'name',
        'display_name',
        'git_remote_path',
        'base_branch',
        'copy_untracked_files',
        'github_connection_id',
        'github_owner',
    ];

    protected function casts(): array
    {
        return [
            'copy_untracked_files' => 'array',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function githubConnection(): BelongsTo
    {
        return $this->belongsTo(GithubConnection::class);
    }
}
