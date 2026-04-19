<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Invitation token for affiliate onboarding. Tracks status (pending/accepted/declined/expired) and links to claimed professional.
class BrandAffiliateInvite extends BaseModel
{
    use HasUuids;

    protected $table = 'brand.brand_affiliate_invites';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'token',
        'status',
        'invite_type',
        'email',
        'email_lc',
        'phone',
        'first_name',
        'last_name',
        'message',
        'claimed_professional_id',
        'accepted_at',
        'expires_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function claimedProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'claimed_professional_id');
    }
}
