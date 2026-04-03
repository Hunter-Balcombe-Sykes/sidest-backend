<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Brand business details (ABN, industries, affiliate visibility). brand_status gates activation in V2.
class BrandProfile extends BaseModel
{
    use HasUuids;

    protected $table = 'brand.brand_profiles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'abn',
        'acn',
        'legal_business_name',
        'business_type',
        'industries',
        'estimated_annual_income',
        'business_website',
        'affiliate_visibility',
        'brand_status',
        'setup_complete',
    ];

    protected $casts = [
        'industries' => 'array',
        'setup_complete' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }
}
