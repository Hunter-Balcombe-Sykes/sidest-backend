<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Jobs\Shopify\CreateShopifySalesChannelJob;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\Hydrogen\HydrogenDeploymentService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Internal endpoints consumed by the Sidest-Embedded Shopify app wizard.
// Auth: VerifyEmbeddedApiKey middleware resolves the brand via X-Shopify-Shop
// and attaches 'embedded_professional_id' to the request.
class EmbeddedSetupController extends ApiController
{
    use NormalizesShopDomain;

    public function __construct(
        private readonly ProfessionalCacheService $cache,
        private readonly BrandCatalogService $catalog,
        private readonly HydrogenDeploymentService $deployment,
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

        $storefrontBaseUrl = $storeSettings && $site
            ? $storeSettings->storefrontBaseUrl($site->subdomain)
            : null;
        $storefrontStatus = $storeSettings && $site
            ? $this->checkStorefrontStatus($storeSettings, $site->subdomain)
            : 'unreachable';

        return $this->success([
            'name'                => (string) ($professional->display_name ?? ''),
            'logo_url'            => '',
            'contact_email'       => (string) ($professional->primary_email ?? ''),
            'contact_number'      => (string) ($professional->phone ?? ''),
            'business_address'    => '',
            'website_url'         => (string) ($brandProfile?->business_website ?? ''),
            'legal_business_name' => (string) ($brandProfile?->legal_business_name ?? ''),
            'abn'                 => (string) ($brandProfile?->abn ?? ''),
            'business_type'       => (string) ($brandProfile?->business_type ?? ''),
            'industries'          => (array) ($brandProfile?->industries ?? []),
            'brand_slug'          => (string) ($site?->subdomain ?? ''),
            // Derived: only true when all wizard fields are populated AND the
            // storefront is actually reachable. Guards against the wizard showing
            // "complete" when Hydrogen has no production deployment.
            'setup_complete'      => (bool) ($brandProfile?->setup_complete ?? false)
                && ! empty($storeSettings?->getRawOriginal('oxygen_deployment_token'))
                && ! empty($storeSettings?->oxygen_storefront_id)
                && (bool) ($storeSettings?->hydrogen_install_confirmed ?? false)
                && (bool) ($storeSettings?->domain_wizard_complete ?? false)
                && $storefrontStatus === 'live',
            // Storefront settings
            'default_commission_rate' => (string) ($storeSettings?->default_commission_rate ?? ''),
            'theme_id'                => (int) ($storeSettings?->theme_id ?? 1),
            'domain_mode'             => (string) ($storeSettings?->domain_mode ?? ''),
            'custom_domain'           => (string) ($storeSettings?->custom_domain ?? ''),
            // Shopify wizard progress fields
            'oxygen_token_set'        => ! empty($storeSettings?->getRawOriginal('oxygen_deployment_token')),
            'oxygen_storefront_id'    => (string) ($storeSettings?->oxygen_storefront_id ?? ''),
            'hydrogen_confirmed'      => (bool) ($storeSettings?->hydrogen_install_confirmed ?? false),
            'domain_provisioned'      => (bool) ($storeSettings?->domain_wizard_complete ?? false),
            'domain_txt_set'          => (bool) ($storeSettings?->domain_txt_confirmed ?? false),
            'storefront_base_url'     => $storefrontBaseUrl,
            'storefront_status'       => $storefrontStatus,
        ]);
    }

    /**
     * Save step 1 brand identity fields to the Professional record.
     */
    public function saveIdentity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'max:255'],
            'contact_email'    => ['sometimes', 'email', 'max:255'],
            'contact_number'   => ['sometimes', 'string', 'max:50'],
            'website_url'      => ['sometimes', 'nullable', 'url', 'max:512'],
        ]);

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

        return $this->success(['message' => 'Profile saved.']);
    }

    /**
     * Save step 2 business detail fields to the BrandProfile record.
     */
    public function saveBusinessDetails(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_business_name' => ['required', 'string', 'max:255'],
            'abn'                 => ['required', 'string', 'max:14'],
            'business_type'       => ['required', 'string', 'max:100'],
            'industries'          => ['required', 'array'],
            'industries.*'        => ['string', 'max:100'],
        ]);

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        BrandProfile::updateOrCreate(
            ['professional_id' => $professionalId],
            [
                'legal_business_name' => $data['legal_business_name'],
                'abn'                 => $data['abn'],
                'business_type'       => $data['business_type'],
                'industries'          => $data['industries'],
            ],
        );

        $this->cache->invalidateProfessional($professional);

        return $this->success(['message' => 'Business details saved.']);
    }

    // ── Store Settings ───────────────────────────────────────────────────────

    /**
     * Patch a single brand store setting by key.
     *
     * Accepted keys: default_commission_rate, theme_id, setup_complete
     */
    public function updateSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'   => ['required', 'string', 'in:default_commission_rate,theme_id,setup_complete'],
            'value' => ['required', 'string'],
        ]);

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        $payload = match ($data['key']) {
            'default_commission_rate' => ['default_commission_rate' => (float) $data['value']],
            'theme_id'                => ['theme_id' => (int) $data['value']],
            // setup_complete lives on BrandProfile, not BrandStoreSettings
            'setup_complete'          => null,
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

        return $this->success(['message' => 'Setting saved.']);
    }

    // ── Deployment Token ─────────────────────────────────────────────────────

    /**
     * Store the Oxygen deployment token and optionally the storefront ID.
     * The token is encrypted at-rest via the model's encrypted cast.
     */
    public function saveDeploymentToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'        => ['required', 'string', 'max:512'],
            'storefront_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

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

        return $this->success([]);
    }

    // ── Analytics Overview ───────────────────────────────────────────────────

    /**
     * Return summary analytics for the brand dashboard overview panel.
     *
     * @return JsonResponse { data: { affiliate_count, total_commission_cents, currency_code,
     *                                commission_30d_cents, revenue_30d_cents,
     *                                recent_sales: [{affiliate_name, commission, occurred_at}] } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');

        $affiliateCount = BrandPartnerLink::where('brand_professional_id', $professionalId)->count();

        // Sum pending + approved commissions (not yet reversed).
        $commissionQuery = CommissionLedgerEntry::where('brand_professional_id', $professionalId)
            ->whereIn('status', ['pending', 'approved']);

        $totalCommissionCents = (int) $commissionQuery->sum('amount_cents');
        $firstEntry = $commissionQuery->select('currency_code')->first();
        $currencyCode = $firstEntry ? (string) $firstEntry->currency_code : 'AUD';

        $thirtyDaysAgo = now()->subDays(30);

        // Commissions earned in the last 30 days (pending + approved).
        $commission30dCents = (int) CommissionLedgerEntry::where('brand_professional_id', $professionalId)
            ->whereIn('status', ['pending', 'approved'])
            ->where('occurred_at', '>=', $thirtyDaysAgo)
            ->sum('amount_cents');

        // Revenue generated through affiliates in the last 30 days.
        // Derived from amount_cents / commission_rate so it works on entries
        // that pre-date the calculation_metadata.line_price_post_discount field.
        // commission_rate is stored as a percentage (e.g. 10 for 10%), and
        // amount_cents = lineTotal_dollars * commission_rate, so
        // revenue_cents = amount_cents * 100 / commission_rate.
        $revenue30dCents = (int) round((float) CommissionLedgerEntry::where('brand_professional_id', $professionalId)
            ->whereIn('status', ['pending', 'approved'])
            ->where('occurred_at', '>=', $thirtyDaysAgo)
            ->where('commission_rate', '>', 0)
            ->selectRaw('COALESCE(SUM(amount_cents * 100.0 / commission_rate), 0) as revenue_cents')
            ->value('revenue_cents'));

        // Last 5 sales with affiliate display name from related Professional record.
        $recentSales = CommissionLedgerEntry::with('affiliateProfessional:id,display_name')
            ->where('brand_professional_id', $professionalId)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get()
            ->map(fn ($entry) => [
                'affiliate_name' => (string) ($entry->affiliateProfessional?->display_name ?? 'Unknown'),
                // Format as decimal string (cents → dollars) with currency suffix.
                'commission'     => number_format($entry->amount_cents / 100, 2).' '.($entry->currency_code ?? ''),
                'occurred_at'    => $entry->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return $this->success([
            'affiliate_count'        => $affiliateCount,
            'total_commission_cents' => $totalCommissionCents,
            'currency_code'          => $currencyCode,
            'commission_30d_cents'   => $commission30dCents,
            'revenue_30d_cents'      => $revenue30dCents,
            'recent_sales'           => $recentSales,
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
                'id'              => $p['gid'] ?? '',
                'title'           => $p['title'] ?? '',
                'image_url'       => $imageUrl,
                'active'          => true,
                'commission_rate' => $metafields['commission_override'] ?? null,
            ];
        }, $activeOnly));

        return $this->success([
            'products'               => $products,
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
     * Handles both modes:
     *   - platform: brand.sidest.co — live once domain_wizard_complete and
     *     domain_txt_confirmed, verifying if only CNAME provisioned, else pending.
     *   - custom: brand-supplied domain — live after TLS, verifying after
     *     ownership verified, else pending.
     *
     * @return JsonResponse { data: { status: 'pending'|'verifying'|'live'|'error', domain: string } }
     */
    public function domainStatus(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');

        $settings = BrandStoreSettings::where('professional_id', $professionalId)->first();

        if (! $settings) {
            return $this->success(['status' => 'pending', 'domain' => '']);
        }

        // Platform domain — derive status from wizard completion flags.
        if ($settings->domain_mode === 'platform') {
            $site = Site::where('professional_id', $professionalId)->first();
            $platformDomain = $site?->subdomain ? "{$site->subdomain}.sidest.co" : '';

            if (! $settings->domain_wizard_complete) {
                return $this->success(['status' => 'pending', 'domain' => $platformDomain]);
            }

            // CNAME exists; TXT confirmed = live, otherwise verifying.
            $status = $settings->domain_txt_confirmed ? 'live' : 'verifying';

            return $this->success(['status' => $status, 'domain' => $platformDomain]);
        }

        // Custom domain.
        if ($settings->domain_mode !== 'custom' || ! $settings->custom_domain) {
            return $this->success(['status' => 'pending', 'domain' => '']);
        }

        $status = 'pending';

        if ($settings->custom_domain_tls_provisioned_at) {
            $status = 'live';
        } elseif ($settings->custom_domain_verified_at) {
            $status = 'verifying';
        }

        return $this->success([
            'status' => $status,
            'domain' => $settings->custom_domain,
        ]);
    }

    // ── Domain Setup ─────────────────────────────────────────────────────────

    /**
     * Provision a platform subdomain (brand.sidest.co) for this brand's Oxygen storefront.
     * Creates a CNAME DNS record via Cloudflare and persists the storefront ID.
     *
     * @return JsonResponse { data: { domain: string } }
     */
    public function setupDomain(Request $request): JsonResponse
    {
        $request->validate([
            'oxygen_storefront_id' => ['required', 'string'],
            // Subdomain input is validated but we use the brand's canonical site subdomain — not
            // this input — for security. The field is accepted to match client expectations.
            'subdomain'            => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,62}$/'],
        ]);

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);

        // Always derive subdomain from the canonical site record — never trust client input.
        $site = Site::where('professional_id', $professionalId)->first();

        if (! $site || ! $site->subdomain) {
            return $this->error('No site subdomain found for this brand.', 422);
        }

        $subdomain = (string) $site->subdomain;

        // CNAME: {subdomain}.sidest.co → shops.myshopify.com, DNS-only (proxied=false).
        // Shopify Oxygen does not support Cloudflare's proxy — it needs a direct CNAME.
        // upsertCname handles existing records so re-running fixes a previously proxied record.
        $dns = new CloudflareDnsService;
        $dns->upsertCname($subdomain, 'shops.myshopify.com', false);

        BrandStoreSettings::updateOrCreate(
            ['professional_id' => $professionalId],
            [
                'oxygen_storefront_id'  => (string) $request->input('oxygen_storefront_id'),
                'domain_mode'           => 'platform',
                'domain_wizard_complete' => true,
            ],
        );

        $this->cache->invalidateProfessional($professional);

        return $this->success(['domain' => "{$subdomain}.sidest.co"]);
    }

    /**
     * Provision the Shopify domain ownership TXT record in Cloudflare on the brand's behalf.
     *
     * Shopify generates a unique verification token when a brand connects a domain to their
     * Hydrogen storefront. Because the domain is brand.sidest.co (our zone), the brand cannot
     * add the record themselves — they copy the token from Shopify and we create:
     *   shopify_verification_{subdomain}.sidest.co TXT → {txt_value}
     *
     * Uses upsertTxt so re-attempts with a freshly generated Shopify token always win.
     *
     * @return JsonResponse { data: { record_name: string } }
     */
    public function provisionDomainTxt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'txt_value' => ['required', 'string', 'max:255'],
        ]);

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
            ['domain_txt_confirmed' => true],
        );

        $this->cache->invalidateProfessional($professional);

        return $this->success(['record_name' => "{$recordName}.sidest.co"]);
    }

    // ── Integration provisioning ─────────────────────────────────────────────

    /**
     * Fully provision the Shopify integration using the embedded app's access token.
     *
     * Called from the Sidest-Embedded wizard immediately after the connection-code
     * step links the brand's Side St account to their Shopify store. The embedded
     * app has already completed Shopify OAuth and holds a fully-scoped access token
     * — storing it here gives the Comet backend everything it needs to run catalog
     * sync, webhook registration, and storefront token creation without requiring
     * the brand to also do a separate OAuth from the Side St dashboard.
     *
     * Safe to call multiple times (idempotent via updateOrCreate).
     *
     * @return JsonResponse { data: { provisioned: bool } }
     */
    public function provisionShopifyIntegration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:512'],
            'shop_id'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'scopes'       => ['sometimes', 'nullable', 'string', 'max:4096'],
        ]);

        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);
        $shopDomain = $this->normalizeShopDomain(
            strtolower(trim((string) $request->header('X-Shopify-Shop', '')))
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
            'shop_domain'                    => $shopDomain,
            'shop_id'                        => $data['shop_id'] ?? Arr::get($existingMetadata, 'shop_id'),
            'scopes'                         => $scopesArray ?: Arr::get($existingMetadata, 'scopes', []),
            'connected_at'                   => now()->toIso8601String(),
            'webhook_registration_state'     => 'queued',
            'connected_via'                  => 'embedded_wizard',
        ]);

        // Dispatch jobs only on first provision OR when a previous provision appears
        // incomplete. Two incomplete signals:
        //   1. webhook state is 'queued' — all jobs likely failed (e.g. bad token)
        //   2. collection handles are missing — metafields/collections jobs didn't finish
        $existingWebhookState = Arr::get($existingMetadata, 'webhook_registration_state', '');
        $collectionsIncomplete = empty(Arr::get($existingMetadata, 'active_collection_handle'))
            && empty(Arr::get($existingMetadata, 'default_collection_handle'));
        $needsJobDispatch = empty($existing?->access_token)
            || $existingWebhookState === 'queued'
            || $collectionsIncomplete;

        $integration = ProfessionalIntegration::updateOrCreate(
            [
                'professional_id' => $professionalId,
                'provider'        => ProfessionalIntegration::PROVIDER_SHOPIFY,
            ],
            [
                'external_account_id'   => $shopDomain,
                'access_token'          => $data['access_token'],
                'last_catalog_sync_error' => null,
                'provider_metadata'     => $metadata,
            ],
        );

        BrandProfile::firstOrCreate(
            ['professional_id' => $professionalId],
            ['setup_complete'  => false],
        );

        // Dispatch setup jobs on initial provision or when the previous attempt
        // appears incomplete (webhook state still queued = jobs likely failed).
        // This endpoint fires on every embedded app load for token refreshes, so
        // guard carefully — successful setups must not re-queue jobs repeatedly.
        if ($needsJobDispatch) {
            $jobs = [
                RegisterShopifyWebhooksJob::class,
                CreateStorefrontAccessTokenJob::class,
                CreateShopifyMetafieldsJob::class,
                CreateShopifySalesChannelJob::class,
                SyncShopifyBrandDesignJob::class,
            ];

            foreach ($jobs as $jobClass) {
                try {
                    $jobClass::dispatch((string) $integration->id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to dispatch embedded integration setup job', [
                        'professional_id' => $professionalId,
                        'job'             => class_basename($jobClass),
                        'message'         => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->cache->invalidateProfessional($professional);

        return $this->success(['provisioned' => true]);
    }

    /**
     * Check whether the storefront is reachable at its base URL.
     *
     * Makes a lightweight GET with redirects disabled so we can
     * distinguish "Hydrogen is serving" (2xx) from "Shopify is
     * falling through to the primary domain" (3xx redirect).
     *
     * @return 'live'|'redirecting'|'unreachable'
     */
    private function checkStorefrontStatus(BrandStoreSettings $settings, string $subdomain): string
    {
        $url = $settings->storefrontBaseUrl($subdomain);

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
                return 'redirecting';
            }

            return 'unreachable';
        } catch (\Throwable) {
            return 'unreachable';
        }
    }
}
