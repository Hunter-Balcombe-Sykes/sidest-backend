<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckoutSession extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.checkout_sessions';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'token',
        'affiliate_professional_id',
        'brand_professional_id',
        'site_id',
        'status',
        'expires_at',
        'converted_at',
        'last_seen_at',
        'context_snapshot',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'converted_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'context_snapshot' => 'array',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(RetailOrder::class, 'checkout_session_token', 'token');
    }
}
