<?php

namespace App\Models;

use App\Enums\CreditEntryType;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One movement of credits. Append-only — a mistake is corrected with a new entry, never by
 * changing or deleting one. Written only through App\Services\Billing\CreditLedger.
 */
class CreditLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'type', 'available_delta_micro', 'held_delta_micro', 'idempotency_key',
        'related_entry_id', 'run_id', 'claude_invocation_id', 'actor_user_id', 'reason', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => CreditEntryType::class,
            'available_delta_micro' => 'integer',
            'held_delta_micro' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Credit ledger entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Credit ledger entries cannot be deleted.'));
    }
}
