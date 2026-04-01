<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandAffiliateSettings extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.brand_affiliate_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'affiliate_professional_id',
        'allow_affiliate_media',
    ];

    protected $casts = [
        'allow_affiliate_media' => 'boolean',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }
}
