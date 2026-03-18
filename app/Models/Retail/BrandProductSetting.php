<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BrandProductSetting extends Model
{
    use HasUuids;

    protected $table = 'retail.brand_product_settings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'shopify_product_id',
        'commission_override',
        'discount_rate',
        'custom_price',
        'is_featured',
        'is_available',
        'sort_order',
    ];

    protected $casts = [
        'commission_override' => 'decimal:2',
        'discount_rate'       => 'decimal:2',
        'custom_price'        => 'decimal:2',
        'is_featured'         => 'boolean',
        'is_available'        => 'boolean',
        'sort_order'          => 'integer',
    ];
}
