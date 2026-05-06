<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Source of truth for Shopify order state. One row per (shop_domain, shopify_order_id).
// Replaces commission_ledger_entries as the order-lifecycle record. Money movements
// (payouts, clawbacks) still live on commerce.commission_movements via FK.
//
// Writes happen exclusively via webhook jobs (ProcessShopifyOrderWebhookJob et al.) which
// run under the app_backend role and bypass RLS. Status transitions go through
// commerce.order_events (audit log).
class Order extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.orders';

    public $incrementing = false;

    protected $keyType = 'string';

    // All writes are server-side. Use forceFill() at callsites.
    protected $guarded = ['*'];

    protected $casts = [
        'gross_cents' => 'integer',
        'discount_cents' => 'integer',
        'refund_cents' => 'integer',
        'net_cents' => 'integer',
        'commission_cents' => 'integer',
        'commission_rate' => 'float',
        'line_items' => 'array',
        'shopify_data' => 'array',
        'shopify_updated_at' => 'datetime',
        'occurred_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'payout_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'order_id')->orderBy('shopify_triggered_at');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }
}
