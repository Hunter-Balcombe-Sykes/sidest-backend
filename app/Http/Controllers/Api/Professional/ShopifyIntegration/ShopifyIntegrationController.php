<?php

namespace App\Http\Controllers\Api\Professional\ShopifyIntegration;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Jobs\Shopify\CreateShopifySalesChannelJob;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Shopify\ShopifyTeardownService;
use App\Services\Shopify\ShopProfileAutoFillService;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Core Shopify integration — connects brand's store, registers order webhooks, creates Storefront API tokens for Hydrogen.
class ShopifyIntegrationController extends ApiController
{
    use NormalizesShopDomain, ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
        private readonly ShopifyTeardownService $teardownService,
    ) {}

    private function currentShopifyIntegrationForBrand(string $brandProfessionalId): ?ProfessionalIntegration
    {
        return ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
    }

    /**
     * @return array{0: string, 1: JsonResponse|null}
     *
     * @throws AuthorizationException (403 or 423) — callers let it propagate.
     */
    private function resolveTargetBrandProfessionalId(
        Request $request,
        ?string $requestedBrandProfessionalId,
        bool $requireForNonBrand,
        string $ability = 'manage'
    ): array {
        $professional = $this->currentProfessional($request);
        $requestedBrandProfessionalId = trim((string) $requestedBrandProfessionalId);

        if ($requestedBrandProfessionalId === '') {
            if ($this->brandAccess->isBrandProfessional($professional)) {
                $requestedBrandProfessionalId = (string) $professional->id;
            } elseif ($requireForNonBrand) {
                return ['', $this->error('brand_professional_id is required for this account type.', 422)];
            } else {
                return ['', null];
            }
        }

        $skeleton = new ProfessionalIntegration([
            'professional_id' => $requestedBrandProfessionalId,
            'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        ]);

        // Throws AuthorizationException (403 or 423) — callers let it propagate.
        $this->authorizeForUser($professional, $ability, $skeleton);

        return [$requestedBrandProfessionalId, null];
    }

    private function ensureShopifyConnected(?ProfessionalIntegration $integration): ?JsonResponse
    {
        if (! $integration || empty($integration->access_token)) {
            return $this->error('Shopify account not connected.', 404);
        }

        return null;
    }

    public function status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            false,
            'view'
        );

        if ($error !== null) {
            return $error;
        }

        if ($targetBrandId === '') {
            return $this->success([
                'eligible' => false,
                'connected' => false,
                'brand_professional_id' => null,
                'shop_domain' => null,
                'shop_id' => null,
                'expires_at' => null,
                'webhook_registration_state' => null,
                'webhook_registration_last_attempt_at' => null,
                'webhook_orders_topic' => null,
            ]);
        }

        $integration = $this->currentShopifyIntegrationForBrand($targetBrandId);
        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = $this->normalizeShopDomain((string) Arr::get($metadata, 'shop_domain', ''));
        // A shop is considered connected as long as the store is linked — the embedded
        // wizard creates a minimal integration row (no access_token) that is still a
        // valid, active connection for dashboard purposes. access_token presence tracks
        // whether the full OAuth has been completed (required for catalog/webhook ops).
        $connected = $integration !== null && $shopDomain !== '';
        $tokenProvisioned = $connected && ! empty($integration?->access_token);

        return $this->success([
            'eligible' => true,
            'connected' => $connected,
            'token_provisioned' => $tokenProvisioned,
            'brand_professional_id' => $targetBrandId,
            'shop_domain' => $connected ? $shopDomain : null,
            'shop_id' => $connected ? (string) Arr::get($metadata, 'shop_id') : null,
            'expires_at' => $integration?->expires_at?->toIso8601String(),
            'webhook_registration_state' => $tokenProvisioned ? Arr::get($metadata, 'webhook_registration_state') : null,
            'webhook_registration_last_attempt_at' => $tokenProvisioned
                ? Arr::get($metadata, 'webhook_registration_last_attempt_at')
                : null,
            'webhook_orders_topic' => $tokenProvisioned
                ? (string) Arr::get($metadata, 'webhook_orders_topic', config('services.shopify.webhook_orders_topic', 'orders/paid'))
                : null,
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
            'shop_domain' => ['required', 'string', 'max:255'],
            'access_token' => ['required', 'string'],
            'refresh_token' => ['sometimes', 'nullable', 'string'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'shop_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:120'],
            'webhook_orders_topic' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shop_data' => ['sometimes', 'nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validated['brand_professional_id']) ? (string) $validated['brand_professional_id'] : null,
            true
        );

        if ($error !== null) {
            return $error;
        }

        $shopDomain = $this->normalizeShopDomain((string) ($validated['shop_domain'] ?? ''));
        if ($shopDomain === '') {
            return $this->error('shop_domain is required.', 422);
        }

        $actorProfessional = $this->currentProfessional($request);

        $conflictingIntegration = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('professional_id', '!=', $targetBrandId)
            ->where('shopify_shop_domain', $shopDomain)
            ->exists();

        if ($conflictingIntegration) {
            return $this->error('This Shopify shop domain is already connected to another brand.', 409);
        }

        $existing = $this->currentShopifyIntegrationForBrand($targetBrandId);
        $existingMetadata = is_array($existing?->provider_metadata) ? $existing->provider_metadata : [];

        $metadata = array_merge($existingMetadata, [
            'shop_domain' => $shopDomain,
            'shop_id' => isset($validated['shop_id']) ? trim((string) $validated['shop_id']) : Arr::get($existingMetadata, 'shop_id'),
            'scopes' => array_values(array_unique(array_filter(array_map(
                static fn ($scope): string => trim((string) $scope),
                Arr::wrap($validated['scopes'] ?? Arr::get($existingMetadata, 'scopes', []))
            ), static fn (string $scope): bool => $scope !== ''))),
            'webhook_orders_topic' => trim((string) ($validated['webhook_orders_topic'] ?? Arr::get(
                $existingMetadata,
                'webhook_orders_topic',
                config('services.shopify.webhook_orders_topic', 'orders/paid')
            ))),
            'connected_at' => now()->toIso8601String(),
            'webhook_registration_state' => 'queued',
        ]);

        $integration = ProfessionalIntegration::query()->updateOrCreate(
            [
                'professional_id' => $targetBrandId,
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
            ],
            [
                'external_account_id' => $shopDomain,
                'access_token' => (string) $validated['access_token'],
                'refresh_token' => isset($validated['refresh_token'])
                    ? (string) $validated['refresh_token']
                    : ($existing?->refresh_token),
                'expires_at' => $validated['expires_at'] ?? null,
                'last_catalog_sync_error' => null,
                'provider_metadata' => $metadata,
            ]
        );

        // Ensure BrandProfile exists (covers manual-signup brands connecting Shopify later)
        BrandProfile::firstOrCreate(
            ['professional_id' => $targetBrandId],
            ['setup_complete' => false]
        );

        // Auto-fill empty profile fields from Shopify shop data (manual-signup → Shopify connect)
        $shopData = $validated['shop_data'] ?? null;
        if (is_array($shopData) && $shopData !== []) {
            $professional = Professional::find($targetBrandId);
            $site = Site::where('professional_id', $targetBrandId)->first();
            $brandProfile = BrandProfile::where('professional_id', $targetBrandId)->first();

            if ($professional && $site) {
                app(ShopProfileAutoFillService::class)->fillFromShopData($professional, $site, $brandProfile, $shopData, $integration);
            }
        }

        $webhookRegistrationQueued = true;
        $jobs = [
            RegisterShopifyWebhooksJob::class,
            CreateStorefrontAccessTokenJob::class,
            CreateShopifyMetafieldsJob::class, // chains → CreateShopifyCollectionsJob
            CreateShopifySalesChannelJob::class,
            // Unified brand-design sync: logos, colours, enums, slogan in one job.
            SyncShopifyBrandDesignJob::class,
        ];

        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch((string) $integration->id);
            } catch (\Throwable $e) {
                $webhookRegistrationQueued = false;
                Log::warning('Failed to dispatch Shopify install job', [
                    'actor_professional_id' => (string) $actorProfessional->id,
                    'brand_professional_id' => $targetBrandId,
                    'integration_id' => (string) $integration->id,
                    'job' => class_basename($jobClass),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->success([
            'connected' => true,
            'brand_professional_id' => $targetBrandId,
            'shop_domain' => $shopDomain,
            'shop_id' => Arr::get($metadata, 'shop_id'),
            'expires_at' => $integration->expires_at?->toIso8601String(),
            'webhook_registration_queued' => $webhookRegistrationQueued,
        ]);
    }

    /**
     * Disconnect the brand's Shopify integration with a full server-side
     * sweep. Unlike a Shopify-initiated uninstall (where the token is
     * revoked BEFORE we hear about it), this runs while we still hold a
     * valid token, so we can delete:
     *   - The Side St Price automatic discount
     *   - The four Side St collections
     *   - Every sidest.* metafield definition (with values)
     *   - The Side St storefront access token
     *   - The Side St sales channel publication
     *   - Revoke the OAuth token itself
     *
     * Then locally:
     *   - Purge affiliate_product_selections scoped to this brand (any
     *     affiliate-curated lists become meaningless once the catalog is
     *     gone)
     *   - Delete the ProfessionalIntegration row
     *
     * Safe to call when no integration exists (returns success with an
     * empty teardown summary). Per-step failures in the Shopify sweep are
     * logged but don't abort the local cleanup — a brand who's already
     * uninstalled in Shopify still gets their local state cleared cleanly.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            true
        );

        if ($error !== null) {
            return $error;
        }

        $actorProfessional = $this->currentProfessional($request);

        $integration = $this->currentShopifyIntegrationForBrand($targetBrandId);

        $teardownSummary = null;
        if ($integration && ! empty($integration->access_token)) {
            try {
                $teardownSummary = $this->teardownService->teardownForIntegration($integration);
            } catch (\Throwable $e) {
                // The teardown service already logs per-step failures; this
                // catch only fires on a truly unexpected exception. We keep
                // going so the local disconnect still runs — leaving the
                // brand half-disconnected (Shopify side still present but
                // Side St thinks it's gone) is worse than orphaning a few
                // Shopify-side artifacts we can't re-reach.
                Log::error('Shopify teardown threw unexpectedly; continuing with local disconnect', [
                    'actor_professional_id' => (string) $actorProfessional->id,
                    'brand_professional_id' => $targetBrandId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Affiliate curated selections only make sense while this brand has
        // a catalog to curate from. Blow them away so the affiliates don't
        // end up with dangling GIDs pointing at deleted products.
        $deletedSelections = AffiliateProductSelection::query()
            ->where('brand_professional_id', $targetBrandId)
            ->delete();

        ProfessionalIntegration::query()
            ->where('professional_id', $targetBrandId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->delete();

        // Clear all wizard progress so the setup wizard starts fresh if the brand reconnects.
        BrandStoreSettings::clearWizardProgress($targetBrandId);
        BrandProfile::where('professional_id', $targetBrandId)
            ->update(['setup_complete' => false]);

        Log::info('Shopify disconnected', [
            'actor_professional_id' => (string) $actorProfessional->id,
            'brand_professional_id' => $targetBrandId,
            'teardown_summary' => $teardownSummary,
            'deleted_selections' => $deletedSelections,
        ]);

        return $this->success([
            'connected' => false,
            'brand_professional_id' => $targetBrandId,
            'teardown' => $teardownSummary,
            'selections_deleted' => $deletedSelections,
        ]);
    }

    public function token(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            true,
            'view'
        );

        if ($error !== null) {
            return $error;
        }

        $integration = $this->currentShopifyIntegrationForBrand($targetBrandId);
        if ($error = $this->ensureShopifyConnected($integration)) {
            return $error;
        }

        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];

        return $this->success([
            'brand_professional_id' => $targetBrandId,
            'connected' => $integration?->access_token !== null,
            'expires_at' => $integration?->expires_at?->toIso8601String(),
            'shop_domain' => $this->normalizeShopDomain((string) Arr::get($metadata, 'shop_domain', '')),
            'shop_id' => Arr::get($metadata, 'shop_id'),
        ]);
    }

    public function registerWebhooks(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            true
        );

        if ($error !== null) {
            return $error;
        }

        $integration = $this->currentShopifyIntegrationForBrand($targetBrandId);
        if ($error = $this->ensureShopifyConnected($integration)) {
            return $error;
        }

        RegisterShopifyWebhooksJob::dispatch((string) $integration->id);

        return $this->success([
            'queued' => true,
            'integration_id' => (string) $integration->id,
            'brand_professional_id' => $targetBrandId,
        ]);
    }

    /**
     * Resolve a merchant-facing Shopify domain (custom primary domain or
     * raw handle) to the canonical `<handle>.myshopify.com` used by the
     * OAuth authorize flow.
     *
     *   mystore                     -> mystore.myshopify.com            (rewrite-only)
     *   mystore.myshopify.com       -> mystore.myshopify.com            (rewrite-only)
     *   https://mystore/admin       -> mystore.myshopify.com            (rewrite-only)
     *   radiorufus.com              -> <handle>.myshopify.com           (HTML discovery)
     *
     * The discovery path fetches the storefront HTML and matches a Shopify
     * storefront global that embeds the canonical shop URL. Most non-headless
     * Shopify themes still expose this — Hydrogen storefronts and heavily
     * stripped themes won't, so the caller must surface a clear fallback
     * error when we return null.
     *
     * Auth-gated (brand professionals + staff) because it lets us make
     * arbitrary outbound HTTP requests on behalf of the caller — an abuse
     * vector if left open.
     */
    public function resolveShop(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'domain' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $raw = (string) $validator->validated()['domain'];
        $host = $this->stripDomainNoise($raw);

        if ($host === '') {
            return $this->error('Enter a valid Shopify store domain.', 422);
        }

        // Already a myshopify handle — no discovery needed.
        if (preg_match('/^([a-z0-9][a-z0-9-]*)\.myshopify\.com$/', $host, $m)) {
            return $this->success(['shop_domain' => "{$m[1]}.myshopify.com"]);
        }

        // Bare handle (no dots) — assume the caller typed the myshopify handle
        // without the TLD and rewrite. This matches the frontend's shortcut UX.
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $host)) {
            return $this->success(['shop_domain' => "{$host}.myshopify.com"]);
        }

        // Anything else is a potential custom storefront domain — try to
        // discover the myshopify handle by scraping the homepage HTML.
        $discovered = $this->discoverShopifyHandle($host);

        if ($discovered === null) {
            return $this->error(
                "Couldn't find a Shopify store at {$host}. Enter your myshopify.com URL instead (Shopify admin → Settings → Domains).",
                404
            );
        }

        return $this->success(['shop_domain' => $discovered]);
    }

    /**
     * Strip scheme, path, querystring, port, and lowercase — leaves just
     * the hostname. Returns '' when the input can't be coerced into a host.
     *
     * Implemented with explicit strpos calls instead of a single regex
     * because including `#` inside a `#`-delimited character class trips
     * PHP's PCRE parser ("Unknown modifier ']'"), and Laravel escalates
     * that warning to a 500. Plain string ops sidestep the ambiguity.
     */
    private function stripDomainNoise(string $raw): string
    {
        $host = strtolower(trim($raw));
        if ($host === '') {
            return '';
        }

        // Strip scheme.
        if (str_starts_with($host, 'https://')) {
            $host = substr($host, 8);
        } elseif (str_starts_with($host, 'http://')) {
            $host = substr($host, 7);
        }

        // Cut at the first boundary character — path, port, query, fragment.
        foreach ([':', '/', '?', '#'] as $boundary) {
            $pos = strpos($host, $boundary);
            if ($pos !== false) {
                $host = substr($host, 0, $pos);
            }
        }

        return $host;
    }

    /**
     * Reject private / link-local / loopback / multicast / reserved addresses
     * before issuing an outbound HTTP request. Prevents the resolveShop endpoint
     * from being abused as an SSRF probe against internal infrastructure.
     *
     * Accepts a host (IP literal or hostname). For hostnames, resolves all A
     * records and rejects if any resolved IP falls in a blocked range.
     */
    private function isPrivateHost(string $host): bool
    {
        $host = trim($host);
        if ($host === '') {
            return true;
        }

        // If $host is a literal IP, just check it.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->ipIsBlocked($host);
        }

        // Otherwise resolve and check every A record.
        $ips = gethostbynamel($host);
        if ($ips === false || empty($ips)) {
            // Non-resolvable — let the caller's Http::get error path handle it.
            return false;
        }

        foreach ($ips as $ip) {
            if ($this->ipIsBlocked($ip)) {
                return true;
            }
        }

        return false;
    }

    private function ipIsBlocked(string $ip): bool
    {
        // NO_PRIV_RANGE  blocks 10/8, 172.16/12, 192.168/16, fc00::/7, fec0::/10
        // NO_RES_RANGE   blocks 0/8, 127/8, 169.254/16, 224/4, 240/4, ::1, fe80::/10
        $notPrivate = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $notPrivate === false;
    }

    /**
     * Fetch the storefront homepage and look for the embedded
     * `Shopify.shop = "<handle>.myshopify.com"` global that most themes
     * render inline. Returns the canonical shop domain or null.
     *
     * Network / parse failures are swallowed and translated into null —
     * the caller turns that into a user-facing 404.
     */
    private function discoverShopifyHandle(string $host): ?string
    {
        // SSRF guard: an authenticated brand can still probe internal infrastructure
        // via this endpoint. Rejecting private/link-local/loopback IPs blocks
        // metadata endpoints (169.254.169.254) and internal services without
        // breaking legitimate custom Shopify domains.
        if ($this->isPrivateHost($host)) {
            Log::info('Shopify resolveShop: rejected private/internal host', ['host' => $host]);

            return null;
        }

        $url = "https://{$host}/";

        try {
            $response = Http::timeout(6)
                ->connectTimeout(4)
                // SSRF hardening: disable redirects. Without this, a public-IP
                // storefront could 302 to an internal host (e.g. 169.254.169.254)
                // and Guzzle would follow it — bypassing isPrivateHost above.
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    // Some Shopify storefronts block default PHP/curl user agents
                    // with a WAF rule. A real-browser UA sidesteps that without
                    // misrepresenting intent.
                    'User-Agent' => 'Mozilla/5.0 (compatible; SidestShopResolver/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::info('Shopify resolveShop: connection failed', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::info('Shopify resolveShop: unexpected error', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = (string) $response->body();

        // Shopify themes commonly embed one of these:
        //   Shopify.shop = "foo.myshopify.com"
        //   "shop":"foo.myshopify.com"
        //   shop: "foo.myshopify.com"
        // All carry the canonical <handle>.myshopify.com; we take the first.
        $patterns = [
            '/Shopify\.shop\s*=\s*["\']([a-z0-9][a-z0-9-]*\.myshopify\.com)["\']/i',
            '/["\']shop["\']\s*:\s*["\']([a-z0-9][a-z0-9-]*\.myshopify\.com)["\']/i',
            '/([a-z0-9][a-z0-9-]*\.myshopify\.com)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $m)) {
                return strtolower($m[1]);
            }
        }

        return null;
    }
}
