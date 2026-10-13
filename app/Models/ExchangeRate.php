<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Units of `currency` per US dollar, as published by `source` on `published_on`. */
class ExchangeRate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['currency', 'rate', 'source', 'published_on'];

    protected function casts(): array
    {
        return ['published_on' => 'date'];
    }
}
