<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Enterprise\Enterprise;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandProduct extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.brand_products';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'enterprise_id',
        'shopify_product_id',
        'title',
        'handle',
        'product_url',
        'image_url',
        'price_cents',
        'currency_code',
        'shopify_status',
        'is_sync_active',
        'last_synced_at',
        'metadata',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'is_sync_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(BrandProductSetting::class, 'brand_product_id');
    }

    public function affiliateOverrides(): HasMany
    {
        return $this->hasMany(BrandProductAffiliateOverride::class, 'brand_product_id');
    }
}
