<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaudeInvocation extends Model
{
    protected $fillable = [
        'run_step_id',
        'command',
        'prompt_text',
        'raw_transcript_path',
        'exit_code',
        'duration_ms',
        'total_cost_usd',
        'num_turns',
        'stop_reason',
        'timed_out',
    ];

    protected function casts(): array
    {
        return [
            'timed_out' => 'boolean',
            'total_cost_usd' => 'decimal:4',
        ];
    }

    public function runStep(): BelongsTo
    {
        return $this->belongsTo(RunStep::class);
    }
}
