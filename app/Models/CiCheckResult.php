<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CiCheckResult extends Model
{
    protected $fillable = [
        'run_id',
        'workflow_name',
        'check_run_id',
        'conclusion',
        'polled_at',
    ];

    protected function casts(): array
    {
        return [
            'polled_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->conclusion, ['success', 'failure', 'cancelled'], true);
    }
}
