<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandProductSetting extends Model
{
    use HasUuids;

    protected $table = 'retail.brand_product_settings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'brand_product_id',
        'shopify_product_id',
        'commission_override',
        'discount_rate',
        'custom_price',
        'is_featured',
        'is_favourite',
        'is_available',
        'sort_order',
        'favourite_sort_order',
    ];

    protected $casts = [
        'commission_override' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'custom_price' => 'decimal:2',
        'is_featured' => 'boolean',
        'is_favourite' => 'boolean',
        'is_available' => 'boolean',
        'sort_order' => 'integer',
        'favourite_sort_order' => 'integer',
    ];

    public function brandProduct(): BelongsTo
    {
        return $this->belongsTo(BrandProduct::class, 'brand_product_id');
    }
}
