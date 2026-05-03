<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

// V2: Idempotent Stripe webhook event log. Prevents double-processing when Stripe retries delivery.
class WebhookEvent extends BaseModel
{
    protected $table = 'billing.webhook_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
