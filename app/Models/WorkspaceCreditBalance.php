<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cached sums of a workspace's ledger entries — only CreditLedger writes it. */
class WorkspaceCreditBalance extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $fillable = ['workspace_id', 'available_micro', 'held_micro'];

    protected function casts(): array
    {
        return ['available_micro' => 'integer', 'held_micro' => 'integer'];
    }
}
