<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only: an audit record can be written, never changed or removed. */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit events cannot be deleted.'));
    }

    /**
     * @param  array<string, mixed>  $meta  never secrets or tokens
     */
    public static function record(string $action, ?Workspace $workspace, ?User $actor, ?Model $subject = null, array $meta = []): self
    {
        return static::create([
            'workspace_id' => $workspace?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
