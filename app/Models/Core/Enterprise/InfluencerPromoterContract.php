<?php

namespace App\Models\Core\Enterprise;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfluencerPromoterContract extends BaseModel
{
    use HasUuids;

    protected $table = 'influencer_promoter_contracts';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'influencer_professional_id',
        'promoter_enterprise_id',
        'status',
        'exclusive',
        'starts_at',
        'ends_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'exclusive' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'influencer_professional_id');
    }

    public function promoterEnterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'promoter_enterprise_id');
    }
}
