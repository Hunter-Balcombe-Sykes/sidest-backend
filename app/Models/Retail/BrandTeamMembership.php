<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Brand team role assignment (owner, finance, marketing, analyst, read_only). Powers BrandAccessService capability checks.
class BrandTeamMembership extends BaseModel
{
    use HasUuids;

    protected $table = 'brand.brand_team_memberships';

    public $incrementing = false;

    protected $keyType = 'string';

    // Only written by server-side team management code. Use forceFill() at callsites.
    protected $guarded = ['*'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function memberProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'member_professional_id');
    }
}
