<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Enterprise\Enterprise;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseProduct extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.enterprise_products';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'enterprise_id',
        'shopify_account_id',
        'brand_id',
        'shopify_product_id',
        'title',
        'handle',
        'product_url',
        'image_url',
        'price_cents',
        'currency_code',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function shopifyAccount(): BelongsTo
    {
        return $this->belongsTo(EnterpriseShopifyAccount::class, 'shopify_account_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(EnterpriseBrand::class, 'brand_id');
    }
}
