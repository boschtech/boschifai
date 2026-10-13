<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/** One credit purchase through a payment provider. */
class Payment extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'provider', 'provider_ref', 'pack', 'credits_micro', 'price_usd_micro',
        'charge_currency', 'charge_amount_minor', 'fx_rate', 'fx_source', 'fx_quoted_at', 'status',
        'failure_reason', 'ledger_entry_id', 'created_by_user_id', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'credits_micro' => 'integer',
            'price_usd_micro' => 'integer',
            'charge_amount_minor' => 'integer',
            'fx_quoted_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
