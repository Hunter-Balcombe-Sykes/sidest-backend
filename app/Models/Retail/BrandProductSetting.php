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
        'is_featured',
        'sort_order',
    ];

    protected $casts = [
        'commission_override' => 'decimal:2',
        'discount_rate'       => 'decimal:2',
        'is_featured'         => 'boolean',
        'sort_order'          => 'integer',
    ];
}
