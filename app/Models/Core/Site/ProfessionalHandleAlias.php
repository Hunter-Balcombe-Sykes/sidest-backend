<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Historical handle alias that still resolves to a professional after they change
// their subdomain. Mirrors SiteSubdomainAlias so old shared URLs (Hydrogen affiliate
// pages, public site lookups) keep resolving for the renamed person instead of 404ing.
class ProfessionalHandleAlias extends BaseModel
{
    use HasUuids;

    protected $table = 'site.professional_handle_aliases';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'professional_id',
        'handle',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }
}
