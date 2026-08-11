<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestExecutionResult extends Model
{
    protected $fillable = [
        'run_id',
        'total',
        'passed',
        'failed',
        'skipped',
        'duration_ms',
        'junit_xml_path',
        'tests',
    ];

    protected function casts(): array
    {
        return [
            'tests' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function passRate(): float
    {
        return $this->total > 0 ? round(($this->passed / $this->total) * 100) : 0.0;
    }
}
