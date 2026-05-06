<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only audit log for commerce.orders. Every webhook/state-change inserts a row.
// Idempotency: unique partial index on shopify_event_id (sourced from X-Shopify-Event-Id).
// PII in metadata is redacted by jsonb_strip_pii() during GDPR jobs.
class OrderEvent extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.order_events';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;  // occurred_at is set by DB; no updated_at

    protected $guarded = ['*'];

    protected $casts = [
        'amount_delta_cents' => 'integer',
        'metadata' => 'array',
        'shopify_triggered_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
