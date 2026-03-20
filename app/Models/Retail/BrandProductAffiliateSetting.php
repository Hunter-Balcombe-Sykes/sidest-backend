<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandProductAffiliateSetting extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.brand_product_affiliate_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'affiliate_professional_id',
        'brand_product_id',
        'commission_override',
        'discount_rate',
        'custom_price',
    ];

    protected $casts = [
        'commission_override' => 'decimal:2',
        'discount_rate' => 'decimal:2',
        'custom_price' => 'decimal:2',
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

    public function brandProduct(): BelongsTo
    {
        return $this->belongsTo(BrandProduct::class, 'brand_product_id');
    }
}
