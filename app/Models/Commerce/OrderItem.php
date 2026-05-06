<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Normalized mirror of commerce.orders.line_items JSONB. Maintained by the
// trg_order_items_diff trigger on commerce.orders. Used for top-products /
// GMV-by-SKU analytics — JSONB has no per-key statistics so a normalized table
// is required for performant aggregate queries.
//
// Per-line commission_cents/commission_rate are pre-computed by the webhook
// handler in PHP (because Postgres triggers can't access product metafields)
// and embedded in each line_items element before upsert.
class OrderItem extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.order_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;  // No created_at/updated_at — denormalized from order

    protected $guarded = ['*'];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'discount_cents' => 'integer',
        'line_total_cents' => 'integer',
        'commission_cents' => 'integer',
        'commission_rate' => 'float',
        'occurred_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }
}
