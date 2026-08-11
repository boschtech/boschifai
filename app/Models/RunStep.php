<?php

namespace App\Models;

use App\Enums\RunStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RunStep extends Model
{
    protected $fillable = [
        'run_id',
        'key',
        'status',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStepStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function claudeInvocations(): HasMany
    {
        return $this->hasMany(ClaudeInvocation::class);
    }
}
