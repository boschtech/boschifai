<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What's left of one purchase or grant of credits, and when it expires (see CreditLedger). */
class CreditLot extends Model
{
    protected $fillable = ['workspace_id', 'ledger_entry_id', 'kind', 'amount_micro', 'remaining_micro', 'expires_at', 'reminded_days'];

    protected function casts(): array
    {
        return [
            'amount_micro' => 'integer',
            'remaining_micro' => 'integer',
            'expires_at' => 'datetime',
            'reminded_days' => 'integer',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
