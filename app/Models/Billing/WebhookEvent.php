<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

// V2: Idempotent Stripe webhook event log. Prevents double-processing when Stripe retries delivery.
//
// payload is intentionally excluded from $fillable — it is inserted only via DB::table()->insertOrIgnore()
// in the webhook controllers, after Stripe HMAC verification and validateEventStructure(). This prevents
// arbitrary data from reaching the column via Eloquent mass-assignment.
class WebhookEvent extends BaseModel
{
    protected $table = 'billing.webhook_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
