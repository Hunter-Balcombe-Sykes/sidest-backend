<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

// V2: OAuth integration record (Square, Fresha, Shopify). Stores encrypted tokens and provider_metadata (webhook IDs, collection handles, etc.).
// PROVIDER_* constants are enforced at the DB level. @see supabase/migrations/202605190000002_add_enum_check_constraints.sql
class ProfessionalIntegration extends BaseModel
{
    use HasUuids;

    public const PROVIDER_SQUARE = 'square';

    public const PROVIDER_FRESHA = 'fresha';

    public const PROVIDER_SHOPIFY = 'shopify';

    /**
     * Canonical vocabulary for provider_metadata JSONB keys.
     *
     * Lives here so a new dev grepping for "what can go in this column"
     * finds the answer next to the column declaration, instead of
     * reconstructing it from a dozen scattered `Arr::get($meta, '...')`
     * call sites. Two keys that used to live here — `disconnected_at` and
     * `webhook_registration_state` — were promoted to real columns in
     * 20260517000000_promote_integration_state_to_columns.sql; the
     * redundant `webhooks_state` duplicate was deleted in the same
     * migration. Anything not on this list should either be added here
     * (with thought given to whether it ought to be a real column) or
     * not written.
     *
     * @var array<int, string>
     */
    public const PROVIDER_METADATA_KEYS = [
        // ── Identity / connection metadata ────────────────────────────
        'shop_domain',                          // Shopify only — canonical shop identity
        'shop_id',                              // Shopify GID for the shop
        'shop_currency',                        // 3-letter ISO; cached so order webhooks don't refetch
        'scopes',                               // array<string> of granted OAuth scopes
        'connected_at',                         // ISO8601 of most-recent successful connect
        'connected_via',                        // 'embedded_wizard' | 'dashboard_oauth' | etc.
        'webhook_orders_topic',                 // 'orders/paid' or 'orders/created' — chosen at connect time

        // ── Disconnection labels (state itself lives on disconnected_at column) ──
        'disconnected_reason',                  // 'app_uninstalled' | 'reconcile_detected_revocation' | etc.
        'reconcile_detection_signal',           // detail string from ReconcileStuckShopifyIntegrationsJob
        'uninstalled_from_status',              // BrandStatus snapshot at uninstall time
        'uninstalled_wizard_state',             // {hydrogen_install_confirmed, oxygen_deployment_token_set, ...}

        // ── Post-install pipeline per-step state ('queued'|'registered'|'synced'|'partial'|'failed') ──
        'metafield_definitions_state',          // CreateShopifyMetafieldsJob
        'sales_channel_state',                  // CreateShopifySalesChannelJob
        'storefront_token_state',               // CreateStorefrontAccessTokenJob
        'brand_design_state',                   // SyncShopifyBrandDesignJob
        'collections_state',                    // CreateShopifyCollectionsJob
        'partna_discount_state',                // CreateShopifyAffiliateDiscountJob

        // ── Webhook registration detail (the gate is the column; these are the audit trail) ──
        'webhooks_registered_at',               // ISO8601 of last successful registration
        'webhooks_results',                     // per-topic map of {state, webhook_id, error}
        'webhooks_error',                       // last failure message
        'webhook_registration_last_attempt_at', // ISO8601 of last attempt (success or failure)

        // ── Shopify-side resources created by the install pipeline ────
        'active_collection_handle',
        'default_collection_handle',
        'favourites_collection_handle',
        'high_commission_collection_handle',
        'custom_photos_enabled',                // brand-wide default toggle (per-product overrides on Shopify metafields)

        // ── Sync / resync bookkeeping ─────────────────────────────────
        'last_resynced_at',                     // ShopifyDataResyncService
        'shopify_sync_locked_fields',           // array<string> — column names ShopProfileAutoFillService must NOT overwrite
    ];

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
        'reconciled_through',
        'disconnected_at',
        'webhook_registration_state',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'storefront_token' => 'encrypted',
        'expires_at' => 'datetime',
        'catalog_latest_time' => 'datetime',
        'last_catalog_sync_at' => 'datetime',
        'provider_metadata' => 'array',
        'reconciled_through' => 'datetime',
        'disconnected_at' => 'datetime',
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
     * Summarise the Shopify post-install pipeline state from provider_metadata.
     *
     * Each of the 5 install jobs writes a per-step state key on success ('registered'
     * or 'synced') and 'failed' on permanent failure. Missing keys mean the job is
     * still pending (queued or in-flight).
     *
     * @return array{state: string, steps: array<string, string|null>}
     *                                                                 state  — 'complete' | 'incomplete' | 'pending'
     *                                                                 steps  — per-job state values keyed by step name
     */
    public function shopifyInstallStatus(): array
    {
        $metadata = is_array($this->provider_metadata) ? $this->provider_metadata : [];

        $steps = [
            // Webhook state lives on a dedicated column post-DATA-2; the other steps
            // are bookkeeping flags that stay in JSONB until they earn their own column.
            'webhooks' => $this->webhook_registration_state,
            'metafields' => Arr::get($metadata, 'metafield_definitions_state'),
            'sales_channel' => Arr::get($metadata, 'sales_channel_state'),
            'storefront_token' => Arr::get($metadata, 'storefront_token_state'),
            'brand_design' => Arr::get($metadata, 'brand_design_state'),
        ];

        $allComplete = true;
        $anyFailed = false;

        foreach ($steps as $state) {
            if ($state !== 'registered' && $state !== 'synced') {
                $allComplete = false;
                if ($state === 'failed' || $state === 'partial') {
                    $anyFailed = true;
                }
            }
        }

        return [
            'state' => $allComplete ? 'complete' : ($anyFailed ? 'incomplete' : 'pending'),
            'steps' => $steps,
        ];
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
