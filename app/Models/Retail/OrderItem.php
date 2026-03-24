<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.order_items';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'brand_professional_id',
        'brand_product_id',
        'shopify_line_item_id',
        'shopify_product_id',
        'shopify_variant_id',
        'title',
        'variant_title',
        'sku',
        'quantity',
        'gross_line_cents',
        'discount_line_cents',
        'refunded_line_cents',
        'returned_line_cents',
        'net_line_cents',
        'currency_code',
        'product_snapshot',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'gross_line_cents' => 'integer',
        'discount_line_cents' => 'integer',
        'refunded_line_cents' => 'integer',
        'returned_line_cents' => 'integer',
        'net_line_cents' => 'integer',
        'product_snapshot' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(RetailOrder::class, 'order_id');
    }

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function brandProduct(): BelongsTo
    {
        return $this->belongsTo(BrandProduct::class, 'brand_product_id');
    }

    public function commissionLedgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class, 'order_item_id');
    }
}
