<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: OAuth integration record (Square, Fresha, Shopify). Stores encrypted tokens and provider_metadata (webhook IDs, collection handles, etc.).
class ProfessionalIntegration extends BaseModel
{
    use HasUuids;

    public const PROVIDER_SQUARE = 'square';

    public const PROVIDER_FRESHA = 'fresha';

    public const PROVIDER_SHOPIFY = 'shopify';

    protected $table = 'core.professional_integrations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'access_token',
        'refresh_token',
        'storefront_token',
        'provider_metadata',
    ];

    protected $fillable = [
        'professional_id',
        'provider',
        'external_account_id',
        'access_token',
        'refresh_token',
        'storefront_token',
        'expires_at',
        'catalog_latest_time',
        'last_catalog_sync_at',
        'last_catalog_sync_error',
        'provider_metadata',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'storefront_token' => 'encrypted',
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

    /**
     * Atomically merge keys into provider_metadata without overwriting
     * keys written by other concurrent jobs.
     *
     * Production (pgsql): uses the jsonb `||` merge operator so the update
     * is a single atomic statement even under concurrent writes from
     * webhooks / onboarding jobs / storefront token creation.
     *
     * Tests (sqlite, via the pgsql→sqlite redirect in TestCase): falls back
     * to a refresh + PHP-side array merge + save. Loss of atomicity is
     * acceptable because the test suite is single-process.
     */
    public function mergeProviderMetadata(array $data): void
    {
        $connection = $this->getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $connection->update(
                "UPDATE {$this->getTable()} SET provider_metadata = COALESCE(provider_metadata, '{}'::jsonb) || ?::jsonb, updated_at = ? WHERE id = ?",
                [$json, now(), $this->id]
            );

            $this->refresh();

            return;
        }

        // SQLite fallback: non-atomic but safe in single-process test runs.
        $this->refresh();
        $current = is_array($this->provider_metadata) ? $this->provider_metadata : [];
        $this->provider_metadata = array_merge($current, $data);
        $this->save();
    }
}
