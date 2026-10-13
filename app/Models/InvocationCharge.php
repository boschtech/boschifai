<?php

namespace App\Models;

use App\Enums\ChargeOutcome;
use Illuminate\Database\Eloquent\Model;

/** What one Claude step cost and what it was (or, in shadow mode, would have been) charged. */
class InvocationCharge extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'claude_invocation_id', 'step_key', 'outcome', 'cost_micro', 'hold_micro',
        'charge_micro', 'markup_millionths', 'enforced', 'ledger_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => ChargeOutcome::class,
            'cost_micro' => 'integer',
            'hold_micro' => 'integer',
            'charge_micro' => 'integer',
            'markup_millionths' => 'integer',
            'enforced' => 'boolean',
        ];
    }
}
