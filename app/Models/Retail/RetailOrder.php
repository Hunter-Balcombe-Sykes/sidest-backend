<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RetailOrder extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.orders';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'shopify_order_id',
        'order_name',
        'source',
        'shop_domain',
        'brand_professional_id',
        'affiliate_professional_id',
        'checkout_session_token',
        'lifecycle_status',
        'financial_status',
        'fulfillment_status',
        'currency_code',
        'gross_cents',
        'refunded_cents',
        'returned_cents',
        'net_cents',
        'ordered_at',
        'paid_at',
        'cancelled_at',
        'closed_at',
        'customer_email_hash',
        'customer_region',
        'shipping_country_code',
        'raw_payload',
    ];

    protected $casts = [
        'gross_cents' => 'integer',
        'refunded_cents' => 'integer',
        'returned_cents' => 'integer',
        'net_cents' => 'integer',
        'ordered_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
        'raw_payload' => 'array',
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

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_token', 'token');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function commissionLedgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class, 'order_id');
    }
}
