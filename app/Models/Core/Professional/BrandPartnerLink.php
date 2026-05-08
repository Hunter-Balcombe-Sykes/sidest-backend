<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand-affiliate connection record. V2 uses single-brand model (slot 0 = primary, one brand per affiliate).
 *
 * @property string|null $site_url Trigger-managed by Postgres; read-only — never mass-assign.
 */
class BrandPartnerLink extends BaseModel
{
    use HasUuids;

    protected $table = 'brand.brand_partner_links';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'affiliate_professional_id',
        'brand_professional_id',
        'slot',
        'custom_photos_enabled',
    ];

    protected $casts = [
        'slot' => 'integer',
        'custom_photos_enabled' => 'boolean',
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
