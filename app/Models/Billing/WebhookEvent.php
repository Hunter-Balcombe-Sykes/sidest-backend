<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use Illuminate\Support\Str;

// V2: Idempotent Stripe webhook event log. Prevents double-processing when Stripe retries delivery.
//
// payload is intentionally excluded from $fillable — it is set only via forceFill() in the webhook
// controllers, after Stripe HMAC verification and validateEventStructure(). This prevents arbitrary
// data from reaching the column via Eloquent mass-assignment.
class WebhookEvent extends BaseModel
{
    protected $table = 'billing.webhook_events';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $webhookEvent) {
            if (empty($webhookEvent->id)) {
                $webhookEvent->id = Str::uuid()->toString();
            }
        });
    }

    protected $fillable = [
        'provider',
        'stripe_event_id',
        'event_type',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
