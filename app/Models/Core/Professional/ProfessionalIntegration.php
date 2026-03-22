<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalIntegration extends BaseModel
{
    use HasUuids;

    public const PROVIDER_SQUARE = 'square';
    public const PROVIDER_FRESHA = 'fresha';
    public const PROVIDER_SHOPIFY = 'shopify';

    protected $table = 'professional_integrations';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'provider',
        'external_account_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'catalog_latest_time',
        'last_catalog_sync_at',
        'last_catalog_sync_error',
        'provider_metadata',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'catalog_latest_time' => 'datetime',
        'last_catalog_sync_at' => 'datetime',
        'provider_metadata' => 'array',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function scopeProvider($query, string $provider)
    {
        return $query->where('provider', mb_strtolower(trim($provider)));
    }
}
