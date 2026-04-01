<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandAffiliateSegment extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.brand_affiliate_segments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'name',
        'description',
        'criteria',
        'size',
        'lookback_days',
        'professional_type_filter',
        'members_refreshed_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'lookback_days' => 'integer',
        'members_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BrandAffiliateSegmentMember::class, 'segment_id');
    }
}
