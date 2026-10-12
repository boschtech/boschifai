<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An emailed invitation to join a workspace. Only a SHA-256 hash of the link's token is stored,
 * so a leaked database can't be used to accept invitations.
 */
class WorkspaceInvitation extends Model
{
    use BelongsToWorkspace;

    public const LIFETIME_DAYS = 7;

    protected $fillable = ['workspace_id', 'email', 'role', 'token_hash', 'invited_by_user_id', 'expires_at', 'accepted_at', 'accepted_by_user_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The invitation for a link's token, if it can still be accepted. */
    public static function findUsable(string $token): ?self
    {
        return static::pending()->where('token_hash', static::hashToken($token))->first();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
