<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HumanApproval extends Model
{
    protected $fillable = [
        'run_id',
        'gate',
        'decision',
        'approved_by',
        'comment',
        'approved_artifact_hash',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'gate' => ApprovalGate::class,
            'decision' => ApprovalDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
