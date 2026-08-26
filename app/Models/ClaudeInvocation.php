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
        'input_tokens',
        'output_tokens',
        'cache_creation_input_tokens',
        'cache_read_input_tokens',
        'stop_reason',
        'timed_out',
    ];

    protected function casts(): array
    {
        return [
            'timed_out' => 'boolean',
            'total_cost_usd' => 'decimal:4',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_creation_input_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
        ];
    }

    public function runStep(): BelongsTo
    {
        return $this->belongsTo(RunStep::class);
    }
}
