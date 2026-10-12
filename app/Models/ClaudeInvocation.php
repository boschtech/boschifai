<?php

namespace App\Models;

use App\Enums\ClaudeBillingMode;
use App\Enums\ClaudeCredentialSource;
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
        'billing_mode',
        'credential_source',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'timed_out' => 'boolean',
            'billing_mode' => ClaudeBillingMode::class,
            'credential_source' => ClaudeCredentialSource::class,
            'total_cost_usd' => 'decimal:4',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_creation_input_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
        ];
    }

    /** Recorded against the run's workspace, so usage can be reported per workspace cheaply. */
    protected static function booted(): void
    {
        static::creating(function (ClaudeInvocation $invocation) {
            $invocation->workspace_id ??= RunStep::whereKey($invocation->run_step_id)
                ->join('runs', 'runs.id', '=', 'run_steps.run_id')
                ->value('runs.workspace_id');
        });
    }

    public function runStep(): BelongsTo
    {
        return $this->belongsTo(RunStep::class);
    }
}
