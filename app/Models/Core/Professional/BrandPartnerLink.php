<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPartnerLink extends BaseModel
{
    use HasUuids;

    protected $table = 'brand_partner_links';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'affiliate_professional_id',
        'brand_professional_id',
        'slot',
    ];

    protected $casts = [
        'slot' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }
}
