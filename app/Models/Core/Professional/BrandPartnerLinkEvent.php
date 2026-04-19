<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only audit log for brand-affiliate link lifecycle events.
// Never mutate existing rows — insert a new event row instead.
class BrandPartnerLinkEvent extends BaseModel
{
    use HasUuids;

    protected $table = 'brand.brand_partner_link_events';

    public $timestamps = false; // only created_at; default via DB.

    protected $fillable = [
        'brand_professional_id',
        'affiliate_professional_id',
        'event_type',
        'actor_type',
        'actor_professional_id',
        'staff_user_id',
        'slot_at_event',
        'pending_commission_count',
        'pending_commission_cents',
        'commissions_voided_count',
        'commissions_voided_cents',
        'reason',
    ];

    protected $casts = [
        'slot_at_event' => 'integer',
        'pending_commission_count' => 'integer',
        'pending_commission_cents' => 'integer',
        'commissions_voided_count' => 'integer',
        'commissions_voided_cents' => 'integer',
        'created_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }

    public function actorProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'actor_professional_id');
    }
}
