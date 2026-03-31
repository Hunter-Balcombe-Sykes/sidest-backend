<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandAffiliateSegmentMember extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.brand_affiliate_segment_members';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'segment_id',
        'affiliate_professional_id',
        'rank',
        'metric_value',
        'created_at',
    ];

    protected $casts = [
        'rank' => 'integer',
        'metric_value' => 'integer',
        'created_at' => 'datetime',
    ];

    public function segment(): BelongsTo
    {
        return $this->belongsTo(BrandAffiliateSegment::class, 'segment_id');
    }

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }
}
