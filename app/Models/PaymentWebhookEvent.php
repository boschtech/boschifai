<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A verified webhook delivery from a payment provider, payload redacted. */
class PaymentWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['provider', 'event_id', 'event_type', 'payment_id', 'payload', 'outcome', 'processed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
