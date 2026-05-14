<?php

namespace App\Http\Controllers\Api\Internal;

use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Http\Requests\Api\Internal\Embedded\ProvisionDomainTxtRequest;
use App\Http\Requests\Api\Internal\Embedded\ProvisionShopifyIntegrationRequest;
use App\Http\Requests\Api\Internal\Embedded\SaveBusinessDetailsRequest;
use App\Http\Requests\Api\Internal\Embedded\SaveDeploymentTokenRequest;
use App\Http\Requests\Api\Internal\Embedded\SaveIdentityRequest;
use App\Http\Requests\Api\Internal\Embedded\SetupDomainRequest;
use App\Http\Requests\Api\Internal\Embedded\UpdateSettingRequest;
use App\Jobs\Shopify\CreateShopifyCollectionsJob;
use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Jobs\Shopify\CreateShopifySalesChannelJob;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\Hydrogen\HydrogenDeploymentService;
use App\Services\Professional\BrandStatusService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Internal endpoints consumed by the Partna embedded Shopify app wizard.
// Auth: shopify.session middleware validates the App Bridge JWT and stashes
// 'embedded_professional_id' + 'embedded_shop_domain' on the request.
class EmbeddedSetupController extends ApiController
{
    use NormalizesShopDomain;

    public function __construct(
        private readonly ProfessionalCacheService $cache,
        private readonly BrandCatalogService $catalog,
        private readonly HydrogenDeploymentService $deployment,
        private readonly CacheLockService $cacheLock,
    ) {}

    // ── Brand Profile ────────────────────────────────────────────────────────

    /**
     * Return all brand data needed to pre-fill the setup wizard.
     *
     * @return JsonResponse { data: BrandProfileShape }
     */
    public function brandProfile(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');

        $professional = Professional::with(['brandProfile', 'site'])->findOrFail($professionalId);
        $brandProfile = $professional->brandProfile;
        $site = $professional->site;
        $storeSettings = BrandStoreSettings::where('professional_id', $professionalId)->first();

        $storefrontBaseUrl = $site?->subdomain
            ? 'https://'.$site->subdomain.'.'.config('partna.public_domain', 'partna.au')
            : null;
        // Cached via CacheLockService (60s TTL, SWR + jitter). The probe itself is
        // a synchronous HTTP GET to the brand's storefront — bypassing the cache
        // would put a 5s timeout in the wizard's setup-prefill path on every poll.
        // BrandStoreSettingsController::update() / deploy() bust this same key
        // when wizard transitions actually flip the underlying state.
        $storefrontStatus = $site?->subdomain
            ? $this->cacheLock->rememberLocked(
                CacheKeyGenerator::brandStorefrontStatus($professionalId),
                60,
                fn () => $this->checkStorefrontStatus($site->subdomain),
            )
            : 'unreachable';

        // Auto-heal wizard flags when infrastructure is already live (e.g.
        // after a reinstall). If the storefront responds, Hydrogen must be
        // installed — backfill so the brand isn't stuck on an already-done step.
        if ($storefrontStatus === 'live' && $storeSettings) {
            $heal = [];
            if (! $storeSettings->hydrogen_install_confirmed) {
                $heal['hydrogen_install_confirmed'] = true;
            }
            if (! empty($heal)) {
                $storeSettings->update($heal);
                $storeSettings->refresh();
            }
        }

        return $this->success([
            'name' => (string) ($professional->display_name ?? ''),
            'logo_url' => '',
            'contact_email' => (string) ($professional->primary_email ?? ''),
            'contact_number' => (string) ($professional->phone ?? ''),
            'business_address' => '',
            'website_url' => (string) ($brandProfile?->business_website ?? ''),
            'legal_business_name' => (string) ($brandProfile?->legal_business_name ?? ''),
            'abn' => (string) ($brandProfile?->abn ?? ''),
            'business_type' => (string) ($brandProfile?->business_type ?? ''),
            'industries' => (array) ($brandProfile?->industries ?? []),
            'brand_slug' => (string) ($site?->subdomain ?? ''),
            // Derived: only true when all wizard fields are populated AND the
            // storefront is actually reachable. Guards against the wizard showing
            // "complete" when Hydrogen has no production deployment.
            'setup_complete' => (bool) ($brandProfile?->setup_complete ?? false)
                && ! empty($storeSettings?->getRawOriginal('oxygen_deployment_token'))
                && ! empty($storeSettings?->oxygen_storefront_id)
                && (bool) ($storeSettings?->hydrogen_install_confirmed ?? false)
                && $storefrontStatus === 'live',
            // Storefront settings
            'default_commission_rate' => (string) ($storeSettings?->default_commission_rate ?? ''),
            'theme_id' => (int) ($storeSettings?->theme_id ?? 1),
            // Shopify wizard progress fields
            'oxygen_token_set' => ! empty($storeSettings?->getRawOriginal('oxygen_deployment_token')),
            'oxygen_storefront_id' => (string) ($storeSettings?->oxygen_storefront_id ?? ''),
            'hydrogen_confirmed' => (bool) ($storeSettings?->hydrogen_install_confirmed ?? false),
            'storefront_base_url' => $storefrontBaseUrl,
            'storefront_status' => $storefrontStatus,
            'brand_status' => $brandProfile?->brand_status ?? BrandStatus::Onboarding->value,
        ]);
    }

    /**
     * Save step 1 brand identity fields to the Professional record.
     */
    public function saveIdentity(SaveIdentityRequest $request): JsonResponse
    {
        $data = $request->validated();

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        $proUpdates = [];
        if (isset($data['name'])) {
            $proUpdates['display_name'] = $data['name'];
        }
        if (isset($data['contact_email'])) {
            $proUpdates['primary_email'] = $data['contact_email'];
        }
        if (isset($data['contact_number'])) {
            $proUpdates['phone'] = $data['contact_number'];
        }
        if (! empty($proUpdates)) {
            $professional->update($proUpdates);
        }

        if (isset($data['website_url'])) {
            BrandProfile::updateOrCreate(
                ['professional_id' => $professionalId],
                ['business_website' => $data['website_url']],
            );
        }

        $this->cache->invalidateProfessional($professional);
        app(BrandStatusService::class)->sync($professional);

        return $this->success(['message' => 'Profile saved.']);
    }

    /**
     * Save step 2 business detail fields to the BrandProfile record.
     */
    public function saveBusinessDetails(SaveBusinessDetailsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        BrandProfile::updateOrCreate(
            ['professional_id' => $professionalId],
            [
                'legal_business_name' => $data['legal_business_name'],
                'abn' => $data['abn'],
                'business_type' => $data['business_type'],
                'industries' => $data['industries'],
            ],
        );

        $this->cache->invalidateProfessional($professional);
        app(BrandStatusService::class)->sync($professional);

        return $this->success(['message' => 'Business details saved.']);
    }

    // ── Store Settings ───────────────────────────────────────────────────────

    /**
     * Patch a single brand store setting by key.
     *
     * Accepted keys: default_commission_rate, theme_id, setup_complete
     */
    public function updateSetting(UpdateSettingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        $payload = match ($data['key']) {
            'default_commission_rate' => ['default_commission_rate' => (float) $data['value']],
            'theme_id' => ['theme_id' => (int) $data['value']],
            // setup_complete lives on BrandProfile, not BrandStoreSettings
            'setup_complete' => null,
        };

        if ($data['key'] === 'setup_complete') {
            BrandProfile::updateOrCreate(
                ['professional_id' => $professionalId],
                ['setup_complete' => filter_var($data['value'], FILTER_VALIDATE_BOOLEAN)],
            );
        } else {
            BrandStoreSettings::updateOrCreate(
                ['professional_id' => $professionalId],
                $payload,
            );
        }

        $this->cache->invalidateProfessional($professional);
        app(BrandStatusService::class)->sync($professional);

        return $this->success(['message' => 'Setting saved.']);
    }

    // ── Deployment Token ─────────────────────────────────────────────────────

    /**
     * Store the Oxygen deployment token and optionally the storefront ID.
     * The token is encrypted at-rest via the model's encrypted cast.
     */
    public function saveDeploymentToken(SaveDeploymentTokenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        $otherFields = [];
        if (array_key_exists('storefront_id', $data)) {
            $otherFields['oxygen_storefront_id'] = $data['storefront_id'];
        }

        $settings = BrandStoreSettings::updateOrCreate(
            ['professional_id' => $professionalId],
            $otherFields,
        );
        // Token is not in $fillable — set directly to avoid mass-assignment
        $settings->oxygen_deployment_token = $data['token'];
        $settings->save();

        $this->cache->invalidateProfessional($professional);
        app(BrandStatusService::class)->sync($professional);

        // Trigger a single-brand Oxygen deployment so the brand's storefront
        // has code on it immediately — they shouldn't have to wait for the
        // next push to sidest-storefront. Best-effort; failures are logged
        // but don't block the wizard.
        if (! empty($settings->oxygen_deployment_token)) {
            $this->deployment->dispatchDeployment($professionalId);
        }

        return $this->success(['message' => 'Deployment token saved.']);
    }

    /**
     * Mark Hydrogen as installed for this brand.
     * Called from the embedded wizard step 2 "I've installed Hydrogen" button.
     */
    public function confirmHydrogenInstall(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        BrandStoreSettings::updateOrCreate(
            ['professional_id' => $professionalId],
            ['hydrogen_install_confirmed' => true],
        );

        $this->cache->invalidateProfessional($professional);
        app(BrandStatusService::class)->sync($professional);

        return $this->success([]);
    }

    // ── Analytics Overview ───────────────────────────────────────────────────

    /**
     * Return summary analytics for the brand dashboard overview panel.
     *
     * @return JsonResponse { data: { affiliate_count, total_commission_cents, currency_code,
     *                      commission_30d_cents, revenue_30d_cents,
     *                      recent_sales: [{affiliate_name, commission, occurred_at}] } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');

        $affiliateCount = BrandPartnerLink::where('brand_professional_id', $professionalId)->count();

        $thirtyDaysAgo = now()->subDays(30);

        // All-time commission earned (approved less reversed) and dominant currency.
        // Phase-4: read commerce.orders directly. commission_movements no longer
        // stores accrual rows — only money movements (payouts/clawbacks/adjustments).
        $allTimeRow = DB::table('commerce.orders')
            ->where('brand_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->selectRaw('COALESCE(SUM(commission_cents), 0) AS commission_cents')
            ->first();
        $totalCommissionCents = (int) ($allTimeRow->commission_cents ?? 0);

        $reversedAllTimeRow = DB::table('commerce.brand_affiliate_rollup')
            ->where('brand_professional_id', $professionalId)
            ->selectRaw('COALESCE(SUM(reversed_commission_cents), 0) AS reversed_cents')
            ->first();
        $totalCommissionCents = max(0, $totalCommissionCents - (int) ($reversedAllTimeRow->reversed_cents ?? 0));

        // Dominant currency in the brand's order history; falls back to AUD when there are no orders yet.
        $currencyRow = DB::table('commerce.orders')
            ->where('brand_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->selectRaw('currency_code, COUNT(*) AS cnt')
            ->groupBy('currency_code')
            ->orderByDesc('cnt')
            ->first();
        $currencyCode = strtoupper((string) ($currencyRow->currency_code ?? 'AUD'));

        // 30-day commission + revenue from commerce.orders.
        $window30dRow = DB::table('commerce.orders')
            ->where('brand_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $thirtyDaysAgo)
            ->selectRaw('
                COALESCE(SUM(commission_cents), 0) AS commission_cents,
                COALESCE(SUM(gross_cents), 0) AS revenue_cents
            ')
            ->first();
        $commission30dCents = (int) ($window30dRow->commission_cents ?? 0);
        $revenue30dCents = (int) ($window30dRow->revenue_cents ?? 0);

        // Last 5 sales — join core.professionals for the affiliate display name.
        $recentSalesRows = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as aff', 'aff.id', '=', 'o.affiliate_professional_id')
            ->where('o.brand_professional_id', $professionalId)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->orderByDesc('o.occurred_at')
            ->limit(5)
            ->select([
                'o.commission_cents',
                'o.currency_code',
                'o.occurred_at',
                'aff.display_name AS affiliate_display_name',
                'aff.handle AS affiliate_handle',
            ])
            ->get();

        $recentSales = $recentSalesRows->map(fn ($row) => [
            'affiliate_name' => (string) ($row->affiliate_display_name
                ?? $row->affiliate_handle
                ?? 'Unknown'),
            // Format as decimal string (cents → dollars) with currency suffix —
            // matches the existing app._index.tsx RecentSale.commission contract.
            'commission' => number_format((int) $row->commission_cents / 100, 2)
                .' '.strtoupper((string) ($row->currency_code ?? '')),
            'occurred_at' => $row->occurred_at
                ? Carbon::parse($row->occurred_at)->toIso8601String()
                : null,
        ])->values()->all();

        return $this->success([
            'affiliate_count' => $affiliateCount,
            'total_commission_cents' => $totalCommissionCents,
            'currency_code' => $currencyCode,
            'commission_30d_cents' => $commission30dCents,
            'revenue_30d_cents' => $revenue30dCents,
            'recent_sales' => $recentSales,
        ]);
    }

    /**
     * Return the brand's active product catalog for the embedded app Products tab.
     *
     * Uses BrandCatalogService to fetch all products with sidest.* metafields
     * from the Shopify Admin API, then maps to a minimal shape for the embedded UI.
     *
     * @return JsonResponse { data: { products: EmbeddedProduct[], default_commission_rate: float } }
     */
    public function embeddedProducts(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);
        $storeSettings = BrandStoreSettings::where('professional_id', $professionalId)->first();
        $defaultRate = (float) ($storeSettings?->default_commission_rate ?? 0);

        try {
            $raw = $this->catalog->fetchBrandCatalog($professional);
        } catch (\Throwable $e) {
            Log::warning('embeddedProducts: catalog fetch failed', [
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return $this->success(['products' => [], 'default_commission_rate' => $defaultRate]);
        }

        // Embedded products tab is read-only and shows the affiliate-visible
        // catalog only — filter to products the brand has actively enabled
        // via the sidest.active metafield.
        $activeOnly = array_filter(is_array($raw) ? $raw : [], function (array $p): bool {
            return ($p['metafields']['active'] ?? null) === true;
        });

        $products = array_values(array_map(function (array $p) {
            $metafields = $p['metafields'] ?? [];
            $images = $p['images'] ?? [];
            $featuredImage = $p['featured_image'] ?? null;
            $imageUrl = $featuredImage['url'] ?? (! empty($images) ? ($images[0]['url'] ?? null) : null);

            return [
                'id' => $p['gid'] ?? '',
                'title' => $p['title'] ?? '',
                'image_url' => $imageUrl,
                'active' => true,
                'commission_rate' => $metafields['commission_override'] ?? null,
            ];
        }, $activeOnly));

        return $this->success([
            'products' => $products,
            'default_commission_rate' => $defaultRate,
        ]);
    }

    /**
     * Queue a Shopify brand design sync for this brand's integration.
     * Triggers BrandDesignImporter to pull theme tokens, colours, and logos.
     * Best-effort — returns success even when no integration exists yet so the
     * button never shows an error to the brand during the post-install window.
     */
    public function syncDesign(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        // No integration yet (e.g. during initial setup) — silently succeed.
        // The design sync job will run once the integration is provisioned.
        if (! $integration) {
            return $this->success([]);
        }

        SyncShopifyBrandDesignJob::dispatch((string) $integration->id);

        return $this->success([]);
    }

    /**
     * Trigger an Oxygen deployment for this brand via GitHub Actions workflow_dispatch.
     *
     * Called from the wizard "Redeploy" button after the brand completes domain setup
     * (connecting the domain + setting it as primary in Shopify Hydrogen). Without a
     * primary domain, deployments go to the preview environment (private). Once the
     * domain is primary, deployments go to production (public).
     */
    public function deployNow(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        $storeSettings = BrandStoreSettings::where('professional_id', $professionalId)->first();

        if (! $storeSettings || empty($storeSettings->oxygen_deployment_token)) {
            return $this->error('No Oxygen deployment token saved. Please complete step 4 first.', 400);
        }

        $this->deployment->dispatchDeployment($professionalId);

        return $this->success(['message' => 'Deployment triggered. It usually takes 1–2 minutes.']);
    }

    // ── Domain Verification ──────────────────────────────────────────────────

    /**
     * Return the current domain verification status for this brand.
     *
     * Platform mode only (brand.partna.au). Status is derived from whether
     * an oxygen_storefront_id has been set (CNAME provisioned).
     *
     * @return JsonResponse { data: { status: 'pending'|'live', domain: string } }
     */
    public function domainStatus(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');

        $settings = BrandStoreSettings::where('professional_id', $professionalId)->first();

        $site = Site::where('professional_id', $professionalId)->first();
        $platformDomain = $site?->subdomain ? "{$site->subdomain}.".config('partna.public_domain') : '';

        // Domain is provisioned when a CNAME has been set (storefront ID saved by setupDomain).
        $status = (! empty($settings?->oxygen_storefront_id)) ? 'live' : 'pending';

        return $this->success(['status' => $status, 'domain' => $platformDomain]);
    }

    // ── Domain Setup ─────────────────────────────────────────────────────────

    /**
     * Provision a platform subdomain (brand.partna.au) for this brand's Oxygen storefront.
     * Creates a CNAME DNS record via Cloudflare and persists the storefront ID.
     *
     * @return JsonResponse { data: { domain: string } }
     */
    public function setupDomain(SetupDomainRequest $request): JsonResponse
    {
        // Subdomain input is validated for format but we use the brand's canonical
        // site subdomain — never the request input — for the CNAME hostname (see below).
        $data = $request->validated();

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        // Always derive subdomain from the canonical site record — never trust client input.
        $site = Site::where('professional_id', $professionalId)->first();

        if (! $site || ! $site->subdomain) {
            return $this->error('No site subdomain found for this brand.', 422);
        }

        $subdomain = (string) $site->subdomain;

        // CNAME: {subdomain}.partna.au → shops.myshopify.com, DNS-only (proxied=false).
        // Shopify Oxygen does not support Cloudflare's proxy — it needs a direct CNAME.
        // upsertCname handles existing records so re-running fixes a previously proxied record.
        $dns = new CloudflareDnsService;
        $dns->upsertCname($subdomain, 'shops.myshopify.com', false);

        BrandStoreSettings::updateOrCreate(
            ['professional_id' => $professionalId],
            [
                'oxygen_storefront_id' => (string) $data['oxygen_storefront_id'],
            ],
        );

        $this->cache->invalidateProfessional($professional);
        app(BrandStatusService::class)->sync($professional);

        return $this->success(['domain' => "{$subdomain}.".config('partna.public_domain')]);
    }

    /**
     * Provision the Shopify domain ownership TXT record in Cloudflare on the brand's behalf.
     *
     * Shopify generates a unique verification token when a brand connects a domain to their
     * Hydrogen storefront. Because the domain is brand.partna.au (our zone), the brand cannot
     * add the record themselves — they copy the token from Shopify and we create:
     *   shopify_verification_{subdomain}.partna.au TXT → {txt_value}
     *
     * Uses upsertTxt so re-attempts with a freshly generated Shopify token always win.
     *
     * @return JsonResponse { data: { record_name: string } }
     */
    public function provisionDomainTxt(ProvisionDomainTxtRequest $request): JsonResponse
    {
        $data = $request->validated();

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        $site = Site::where('professional_id', $professionalId)->first();
        if (! $site || ! $site->subdomain) {
            return $this->error('No site subdomain found for this brand.', 422);
        }

        $subdomain = (string) $site->subdomain;
        $recordName = "shopify_verification_{$subdomain}";

        $dns = new CloudflareDnsService;
        $dns->upsertTxt($recordName, (string) $data['txt_value']);

        BrandStoreSettings::updateOrCreate(
            ['professional_id' => $professionalId],
            [],
        );

        $this->cache->invalidateProfessional($professional);
        app(BrandStatusService::class)->sync($professional);

        return $this->success(['record_name' => "{$recordName}.".config('partna.public_domain')]);
    }

    // ── Integration provisioning ─────────────────────────────────────────────

    /**
     * Fully provision the Shopify integration using the embedded app's access token.
     *
     * Called from the Sidest-Embedded wizard immediately after the connection-code
     * step links the brand's Partna account to their Shopify store. The embedded
     * app has already completed Shopify OAuth and holds a fully-scoped access token
     * — storing it here gives the Partna backend everything it needs to run catalog
     * sync, webhook registration, and storefront token creation without requiring
     * the brand to also do a separate OAuth from the Partna dashboard.
     *
     * Safe to call multiple times (idempotent via updateOrCreate).
     *
     * @return JsonResponse { data: { provisioned: bool } }
     */
    public function provisionShopifyIntegration(ProvisionShopifyIntegrationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);
        // Shop domain comes from the JWT `dest` claim, stashed by VerifyShopifySessionToken.
        // The legacy X-Shopify-Shop header was removed when the static-key auth path
        // was deleted — trusting it would re-introduce the cross-tenant compromise risk
        // that motivated the JWT migration in the first place.
        $shopDomain = $this->normalizeShopDomain(
            (string) $request->attributes->get('embedded_shop_domain', '')
        );

        $existing = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        $existingMetadata = is_array($existing?->provider_metadata) ? $existing->provider_metadata : [];

        $scopesArray = [];
        if (! empty($data['scopes'])) {
            $scopesArray = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $data['scopes'])
            )));
        }

        $metadata = array_merge($existingMetadata, [
            'shop_domain' => $shopDomain,
            'shop_id' => $data['shop_id'] ?? Arr::get($existingMetadata, 'shop_id'),
            'scopes' => $scopesArray ?: Arr::get($existingMetadata, 'scopes', []),
            'connected_at' => now()->toIso8601String(),
            'webhook_registration_state' => 'queued',
            'connected_via' => 'embedded_wizard',
        ]);

        // Clear disconnected_* — stamped by ShopifyAppUninstalledWebhookController
        // on uninstall, never auto-cleared on reinstall. Without removal here,
        // BrandStatusService::determine() keeps returning Disconnected (its
        // first check), which traps the embedded app's wizard in a redirect
        // loop (app.tsx loader: brand_status === 'disconnected' → needsReconnect
        // → /shopify-setup, repeat). Both keys must be cleared — the uninstall
        // webhook writes both `disconnected_at` (timestamp) and `disconnected_reason`
        // ('app_uninstalled'); clearing only one used to leave the brand stuck.
        unset($metadata['disconnected_at']);
        unset($metadata['disconnected_reason']);

        // Dispatch jobs only on first provision OR when a previous provision appears
        // incomplete. Two incomplete signals:
        //   1. webhook state is 'queued' — all jobs likely failed (e.g. bad token)
        //   2. ANY required collection handle is missing — metafields/collections
        //      jobs partially failed. Smart collections (active, high-commission)
        //      depend on metafield definitions, so a race in the initial setup can
        //      leave default + favourites created but active + high-commission
        //      missing. Checking each handle individually catches that partial
        //      state — the old `empty(active) && empty(default)` check missed it.
        $existingWebhookState = Arr::get($existingMetadata, 'webhook_registration_state', '');
        $collectionsIncomplete = empty(Arr::get($existingMetadata, 'active_collection_handle'))
            || empty(Arr::get($existingMetadata, 'default_collection_handle'))
            || empty(Arr::get($existingMetadata, 'favourites_collection_handle'))
            || empty(Arr::get($existingMetadata, 'high_commission_collection_handle'));
        $needsJobDispatch = empty($existing?->access_token)
            || $existingWebhookState === 'queued'
            || $collectionsIncomplete;

        // Detect a pure token-refresh no-op: integration already exists with the same
        // access token and setup is complete. The embedded app calls this on every
        // admin page load, so the no-op path must skip status sync + cache busting —
        // those are cross-region DB queries and Redis writes that nothing depends on
        // when the brand's underlying state is unchanged.
        $isNoOpRefresh = ! $needsJobDispatch
            && $existing !== null
            && $existing->access_token === $data['access_token'];

        // Validate-before-store: the embedded app re-posts session.accessToken on
        // every load. Validate every persist against Shopify Admin API — invalid
        // tokens (rotated, revoked, scope-mismatch) and cross-shop mixups (shop A
        // submitting shop B's access token) get rejected before they overwrite a
        // working credential. Transient Shopify outages return valid=true so we
        // don't punish merchants for backend hiccups; only definitive 401 or
        // shop-domain-mismatch refuse.
        $validation = $this->validateShopifyAccessToken($shopDomain, $data['access_token']);
        if (! $validation['valid']) {
            Log::warning('Shopify provision-integration: token rejected by Shopify; refusing to overwrite existing token.', [
                'professional_id' => $professionalId,
                'shop_domain' => $shopDomain,
                'reason' => $validation['reason'],
            ]);

            // Structured `reason` field lets Remix auto-heal — when the Shopify
            // app is uninstalled+reinstalled, Shopify revokes the old access
            // token but the Remix-side PrismaSession still caches it. Without
            // a typed reason, Remix would have to string-match the message to
            // know whether to clear its session cache. See
            // `Partna-Shopify-App/app/routes/app.tsx` for the consumer.
            return response()->json([
                'message' => 'Shopify rejected the new access token. The Remix-side SDK session will be cleared so the next embedded load runs Token Exchange to issue a fresh credential.',
                'reason' => 'shopify_token_rejected',
            ], 422);
        }

        $integration = ProfessionalIntegration::updateOrCreate(
            [
                'professional_id' => $professionalId,
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
            ],
            [
                'external_account_id' => $shopDomain,
                'access_token' => $data['access_token'],
                'last_catalog_sync_error' => null,
                'provider_metadata' => $metadata,
            ],
        );

        BrandProfile::firstOrCreate(
            ['professional_id' => $professionalId],
            ['setup_complete' => false],
        );

        // Dispatch setup jobs on initial provision or when the previous attempt
        // appears incomplete (webhook state still queued = jobs likely failed).
        // This endpoint fires on every embedded app load for token refreshes, so
        // guard carefully — successful setups must not re-queue jobs repeatedly.
        if ($needsJobDispatch) {
            // CreateShopifyCollectionsJob is included explicitly (in addition to
            // being chained from CreateShopifySalesChannelJob) so a re-provision
            // where sales-channel is already registered still recreates any
            // missing collections. The job is ShouldBeUnique + findOrCreate-by-
            // title so the redundant trigger is a safe no-op when collections
            // are already complete.
            $jobs = [
                RegisterShopifyWebhooksJob::class,
                CreateStorefrontAccessTokenJob::class,
                CreateShopifyMetafieldsJob::class,
                CreateShopifySalesChannelJob::class,
                CreateShopifyCollectionsJob::class,
                SyncShopifyBrandDesignJob::class,
            ];

            foreach ($jobs as $jobClass) {
                try {
                    $jobClass::dispatch((string) $integration->id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to dispatch embedded integration setup job', [
                        'professional_id' => $professionalId,
                        'job' => class_basename($jobClass),
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Skip cache invalidation + status sync on no-op token refreshes. Brand
        // status can't have changed when nothing about the integration changed,
        // and avoiding sync() saves ~8–12 cross-region DB queries per page load.
        if (! $isNoOpRefresh) {
            $this->cache->invalidateProfessional($professional);
            app(BrandStatusService::class)->sync($professional);
        }

        return $this->success(['provisioned' => true]);
    }

    /**
     * Check whether the storefront is reachable at its base URL.
     *
     * Makes a lightweight GET with redirects disabled. A 2xx response
     * means the storefront is serving pages directly. A 3xx redirect
     * is treated as "live" when it carries Oxygen/Hydrogen response
     * headers — the app code intentionally redirects the root (e.g.
     * Mode B redirecting to the Shopify store). Without those headers
     * the redirect is a domain misconfiguration (not primary).
     *
     * @return 'live'|'redirecting'|'unreachable'
     */
    private function checkStorefrontStatus(string $subdomain): string
    {
        $url = 'https://'.$subdomain.'.'.config('partna.public_domain', 'partna.au');

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            if ($response->successful()) {
                return 'live';
            }

            if ($response->redirect()) {
                // If the redirect comes from an Oxygen/Hydrogen deployment,
                // the storefront is live — the redirect is app-level logic
                // (e.g. root → Shopify store), not a domain misconfig.
                $poweredBy = $response->header('powered-by') ?? '';
                if (str_contains($poweredBy, 'Oxygen') || str_contains($poweredBy, 'Hydrogen')) {
                    return 'live';
                }

                return 'redirecting';
            }

            return 'unreachable';
        } catch (\Throwable) {
            return 'unreachable';
        }
    }

    /**
     * Verify a candidate Shopify access token works against Shopify Admin API
     * before persisting it. Asserts both that the token is accepted (200) and
     * that the shop it returns matches the requesting shop — guards against
     * the cross-shop bug where shop A submits shop B's access token.
     *
     * Outcomes:
     *   200 + matching domain   → ['valid' => true,  'reason' => null]
     *   200 + domain mismatch   → ['valid' => false, 'reason' => 'shop_domain_mismatch']
     *   401                     → ['valid' => false, 'reason' => 'invalid_token']
     *   5xx / network error     → ['valid' => true,  'reason' => 'transient_outage']
     *                             (don't punish merchants for Shopify hiccups)
     *   anything else           → ['valid' => false, 'reason' => 'unexpected_status_<code>']
     *
     * @return array{valid: bool, reason: ?string}
     */
    private function validateShopifyAccessToken(string $shopDomain, string $accessToken): array
    {
        $shopDomain = strtolower(trim($shopDomain, ' /'));

        if ($accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            return ['valid' => false, 'reason' => 'malformed_input'];
        }

        $apiVersion = (string) config('services.shopify.api_version', '2026-04');
        $url = "https://{$shopDomain}/admin/api/{$apiVersion}/shop.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Accept' => 'application/json',
            ])->timeout(10)->get($url);
        } catch (\Throwable $e) {
            // Class name only — message can contain URLs / tokens.
            Log::warning('shopify_access_token_validation_network_error', [
                'shop_domain' => $shopDomain,
                'error_class' => class_basename($e),
            ]);

            return ['valid' => true, 'reason' => 'transient_outage'];
        }

        if ($response->status() === 401) {
            return ['valid' => false, 'reason' => 'invalid_token'];
        }

        if ($response->status() >= 500) {
            Log::warning('shopify_access_token_validation_5xx', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            return ['valid' => true, 'reason' => 'transient_outage'];
        }

        if (! $response->successful()) {
            return ['valid' => false, 'reason' => 'unexpected_status_'.$response->status()];
        }

        $body = $response->json();
        $responseDomain = strtolower(trim((string) ($body['shop']['myshopify_domain'] ?? ''), ' /'));

        if ($responseDomain === '' || $responseDomain !== $shopDomain) {
            Log::warning('shopify_access_token_domain_mismatch', [
                'expected_shop_domain' => $shopDomain,
                'response_shop_domain' => $responseDomain,
            ]);

            return ['valid' => false, 'reason' => 'shop_domain_mismatch'];
        }

        return ['valid' => true, 'reason' => null];
    }
}
