<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A customer's separate space: its own repositories, GitHub connections, runs and usage. */
class Workspace extends Model
{
    protected $fillable = ['name', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->slug ??= static::uniqueSlug($workspace->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * The external ID this workspace's cloud roles must require when trusting BoschifAI (AWS
     * "confused deputy" protection): unguessable, created on first use and never changed.
     */
    public function aiExternalId(): string
    {
        if (! $this->ai_external_id) {
            // Write-once in the database, then read back: a stale copy of this workspace must
            // never replace an ID a customer has already put in their trust policy.
            static::whereKey($this->id)->whereNull('ai_external_id')
                ->update(['ai_external_id' => 'boschifai-'.Str::lower(Str::random(32))]);
            $this->ai_external_id = static::whereKey($this->id)->value('ai_external_id');
            $this->syncOriginalAttribute('ai_external_id');
        }

        return $this->ai_external_id;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function addMember(User $user, WorkspaceRole $role): void
    {
        $this->users()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    public function repoConfigs(): HasMany
    {
        return $this->hasMany(RepoConfig::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }
}
