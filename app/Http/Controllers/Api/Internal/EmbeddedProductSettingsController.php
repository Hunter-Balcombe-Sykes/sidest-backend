<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveEmbeddedProfessional;
use App\Http\Requests\Api\Internal\Embedded\UpdateProductSettingsRequest;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Backs the sidest-product-settings Shopify admin UI extension.
//
// GET  /internal/embedded/product-settings  — load metafields, collections, variants
// PATCH /internal/embedded/product-settings — save metafield changes (per-field)
//
// Auth: shopify.session middleware resolves the brand via the App Bridge session
// token and injects embedded_professional_id into the request.
class EmbeddedProductSettingsController extends ApiController
{
    use ResolveEmbeddedProfessional;

    public function __construct(
        private readonly BrandCatalogService $catalog,
        private readonly CacheLockService $cacheLock,
    ) {}

    /**
     * GET — Return product metafields, collection membership, variant list,
     * and global settings the extension needs on mount.
     *
     * Cached per (brand, product) for 5 minutes via CacheLockService — single-
     * flight + ±20% jitter + SWR. Busted by update() on every successful patch
     * so the next mount sees the new value immediately. Mirrors the cache shape
     * of EmbeddedProductAnalyticsController::show.
     *
     * @return JsonResponse {
     *                      active: bool,
     *                      commission_override: float|null,
     *                      affiliate_discount_pct: float|null,
     *                      custom_photos_enabled: bool|null,
     *                      default_commission_rate: float,
     *                      global_custom_photos_enabled: bool,
     *                      in_favourites_collection: bool,
     *                      in_default_collection: bool,
     *                      variants: Array<{ gid: string, title: string, enabled: bool }>,
     *                      }
     */
    public function show(Request $request): JsonResponse
    {
        // Read-only — ID-only resolution keeps the cache-hit path from loading
        // core.professionals.
        $professionalId = $this->currentEmbeddedProfessionalId($request);
        $productGid = (string) $request->query('product_gid', '');

        if ($productGid === '') {
            return $this->error('product_gid is required.', 422);
        }

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('No Shopify integration found.', 404);
        }

        $productId = $this->extractId($productGid);
        $cacheKey = CacheKeyGenerator::embeddedProductSettings($professionalId, $productId);

        // Int TTL (300s) — DateTimeInterface TTLs skip writeWithJitter's ±20%
        // jitter. Bust on every successful update() so a save reflects in the
        // very next mount instead of waiting up to 5 minutes.
        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            300,
            fn () => $this->buildSettingsPayload($integration, $productGid, $productId, $professionalId),
        );

        return $this->success($payload);
    }

    /**
     * PATCH — Save a single field change. The extension saves per-field
     * on change (no monolithic Save button).
     *
     * Body validated by UpdateProductSettingsRequest:
     *   - product_gid: shopify Product GID
     *   - field: one of seven allowlisted keys
     *   - value: per-field rules (numeric 0..100 / boolean / array of variant GIDs)
     */
    public function update(UpdateProductSettingsRequest $request): JsonResponse
    {
        $professional = $this->currentEmbeddedProfessional($request);
        $professionalId = (string) $professional->id;
        $productGid = (string) $request->input('product_gid');
        $field = (string) $request->input('field');
        $value = $request->input('value');

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('No Shopify integration found.', 404);
        }

        // Gate on the loaded integration row (not a skeleton) so the policy
        // sees the actual professional_id rather than one inferred from the
        // request — matches the IntegrationPolicy::manage pattern used by the
        // dashboard Shopify endpoints.
        $this->authorizeForUser($professional, 'manage', $integration);

        try {
            match ($field) {
                'active',
                'commission_override',
                'affiliate_discount_pct',
                'custom_photos_enabled',
                'disabled_variant_gids' => $this->saveMetafield($integration, $productGid, $field, $value),

                'add_to_favourites' => $this->toggleCollection(
                    $integration, $productGid, 'favourites_collection_handle', (bool) $value
                ),

                'add_to_default' => $this->toggleCollection(
                    $integration, $productGid, 'default_collection_handle', (bool) $value
                ),
            };
        } catch (\Throwable $e) {
            Log::error('Embedded product settings save failed.', [
                'professional_id' => $professionalId,
                'product_gid' => $productGid,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage(), 422);
        }

        // Bust the settings cache (primary + SWR :stale twin) so the next mount
        // sees the just-written value. Without this, save → reload would still
        // serve the pre-write payload from cache for up to ~50 minutes (5m
        // primary + 50m stale window).
        $productId = $this->extractId($productGid);
        $settingsKey = CacheKeyGenerator::embeddedProductSettings($professionalId, $productId);
        Cache::forget($settingsKey);
        Cache::forget($settingsKey.':stale');

        // If the change touched the `active` flag, also bust the per-product
        // active cache used by EmbeddedProductAnalyticsController::resolveActive.
        // BrandCatalogService::bustCatalogCaches handles this when writes flow
        // through saveProductMetafields, but this controller does its own raw
        // GraphQL writes — so we mirror the bust here. (No :stale twin: that
        // key is written by rememberLockedNullable.)
        if ($field === 'active') {
            Cache::forget(CacheKeyGenerator::embeddedProductActive($professionalId, $productId));
        }

        // If custom_photos_enabled changed at the per-product level, bust the
        // brandProductCustomPhotos lookup so the affiliate-facing read sees the
        // new value. Same rationale as above — bypassing BrandCatalogService.
        if ($field === 'custom_photos_enabled') {
            Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($professionalId, $productGid));
        }

        return $this->success(['message' => 'Saved.']);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function extractId(string $gid): string
    {
        return (string) preg_replace('#^gid://shopify/Product/#', '', $gid);
    }

    /**
     * Assemble the full settings payload returned to the extension. Extracted
     * from show() so rememberLocked()'s closure has a single, mockable seam.
     *
     * @return array<string, mixed>
     */
    private function buildSettingsPayload(
        ProfessionalIntegration $integration,
        string $productGid,
        string $productId,
        string $professionalId,
    ): array {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        // Single Admin API call — metafields and variants are fetched in one
        // GraphQL query to avoid an extra round-trip.
        $result = $this->fetchProductMetafields($integration, $productId);
        $metafields = $result['metafields'];
        $variants = $result['variants'];

        // Check collection membership
        $inFavourites = $this->isInCollection($metadata, 'favourites_collection_handle', $productGid, $integration);
        $inDefault = $this->isInCollection($metadata, 'default_collection_handle', $productGid, $integration);

        // Global settings
        $storeSettings = BrandStoreSettings::where('professional_id', $professionalId)->first();
        $defaultCommissionRate = (float) ($storeSettings?->default_commission_rate ?? 15);
        $globalCustomPhotosEnabled = (bool) Arr::get($metadata, 'custom_photos_enabled', false);

        return [
            'active' => $metafields['active'] ?? true,
            'commission_override' => $metafields['commission_override'] ?? null,
            'affiliate_discount_pct' => $metafields['affiliate_discount_pct'] ?? null,
            'custom_photos_enabled' => $metafields['custom_photos_enabled'] ?? null,
            'default_commission_rate' => $defaultCommissionRate,
            'global_custom_photos_enabled' => $globalCustomPhotosEnabled,
            'in_favourites_collection' => $inFavourites,
            'in_default_collection' => $inDefault,
            'variants' => $variants,
        ];
    }

    /**
     * Fetch sidest.* metafields and variants for a single product via Shopify
     * Admin API in a single GraphQL call.
     *
     * @return array{metafields: array, variants: array}
     */
    private function fetchProductMetafields(ProfessionalIntegration $integration, string $productId): array
    {
        $shopDomain = trim((string) Arr::get($integration->provider_metadata ?? [], 'shop_domain', ''));
        $adminToken = trim((string) ($integration->access_token ?? ''));
        $apiVersion = config('services.shopify.api_version');

        $empty = ['metafields' => [], 'variants' => []];

        if ($shopDomain === '' || $adminToken === '') {
            return $empty;
        }

        $query = <<<'GRAPHQL'
query productMetafields($id: ID!) {
  product(id: $id) {
    active: metafield(namespace: "partna", key: "active") { value }
    commissionOverride: metafield(namespace: "partna", key: "commission_override") { value }
    affiliateDiscountPct: metafield(namespace: "partna", key: "affiliate_discount_pct") { value }
    customPhotosEnabled: metafield(namespace: "partna", key: "custom_photos_enabled") { value }
    variants(first: 50) {
      edges {
        node {
          id
          title
          enabled: metafield(namespace: "partna", key: "enabled") { value }
        }
      }
    }
  }
}
GRAPHQL;

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
                ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                    'query' => $query,
                    'variables' => ['id' => "gid://shopify/Product/{$productId}"],
                ]);

            $data = $response->json();

            if (! $response->successful() || ! empty(Arr::get($data, 'errors', []))) {
                Log::warning('Shopify Admin API error fetching product metafields.', [
                    'shop_domain' => $shopDomain,
                    'product_id' => $productId,
                    'status' => $response->status(),
                    'errors' => Arr::get($data, 'errors', []),
                ]);

                return $empty;
            }

            $product = Arr::get($data, 'data.product', []);

            if (empty($product)) {
                return $empty;
            }

            // Extract variant data from the same response instead of making a
            // second API call in fetchVariants().
            $variantEdges = Arr::get($product, 'variants.edges', []);
            $variants = [];
            if (is_array($variantEdges)) {
                foreach ($variantEdges as $edge) {
                    $node = $edge['node'] ?? [];
                    $variants[] = [
                        'gid' => (string) ($node['id'] ?? ''),
                        'title' => (string) ($node['title'] ?? ''),
                        'enabled' => $this->parseBool(Arr::get($node, 'enabled.value'), true),
                    ];
                }
            }

            return [
                'metafields' => [
                    'active' => $this->parseBool(Arr::get($product, 'active.value')),
                    'commission_override' => $this->parseFloat(Arr::get($product, 'commissionOverride.value')),
                    'affiliate_discount_pct' => $this->parseFloat(Arr::get($product, 'affiliateDiscountPct.value')),
                    'custom_photos_enabled' => $this->parseBool(Arr::get($product, 'customPhotosEnabled.value')),
                ],
                'variants' => $variants,
            ];
        } catch (\Throwable $e) {
            Log::error('Shopify Admin API exception fetching product metafields.', [
                'shop_domain' => $shopDomain,
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    /**
     * Fetch variant list with per-variant sidest.enabled state.
     *
     * Write-path helper used by saveVariantEnabledStates to compute which
     * variants need their metafield flipped. On Shopify failure we MUST surface
     * the error rather than swallow it — returning [] would let
     * saveVariantEnabledStates believe the product has no variants and
     * silently skip all writes, then update() would respond "Saved." while
     * nothing changed. Re-throw so update()'s catch returns 422 instead.
     *
     * @return array<int, array{gid: string, title: string, enabled: bool}>
     */
    private function fetchVariants(ProfessionalIntegration $integration, string $productId): array
    {
        $shopDomain = trim((string) Arr::get($integration->provider_metadata ?? [], 'shop_domain', ''));
        $adminToken = trim((string) ($integration->access_token ?? ''));
        $apiVersion = config('services.shopify.api_version');

        if ($shopDomain === '' || $adminToken === '') {
            throw new \RuntimeException('Shopify integration is missing credentials.');
        }

        $query = <<<'GRAPHQL'
query productVariants($id: ID!) {
  product(id: $id) {
    variants(first: 50) {
      edges {
        node {
          id
          title
          enabled: metafield(namespace: "partna", key: "enabled") { value }
        }
      }
    }
  }
}
GRAPHQL;

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
                ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                    'query' => $query,
                    'variables' => ['id' => "gid://shopify/Product/{$productId}"],
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException("Shopify API returned {$response->status()}");
            }

            $data = $response->json();
            if (! empty(Arr::get($data, 'errors', []))) {
                $first = Arr::get($data, 'errors.0.message', 'Unknown GraphQL error');
                throw new \RuntimeException("Shopify GraphQL error: {$first}");
            }

            $edges = Arr::get($data, 'data.product.variants.edges', []);

            if (! is_array($edges)) {
                return [];
            }

            return array_map(function ($edge) {
                $node = $edge['node'] ?? [];

                return [
                    'gid' => (string) ($node['id'] ?? ''),
                    'title' => (string) ($node['title'] ?? ''),
                    'enabled' => $this->parseBool(Arr::get($node, 'enabled.value'), true),
                ];
            }, $edges);
        } catch (\Throwable $e) {
            Log::warning('Shopify Admin API exception fetching variants.', [
                'shop_domain' => $shopDomain,
                'product_id' => $productId,
                'operation' => 'fetchVariants',
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            // Re-throw so saveVariantEnabledStates → update() returns 422
            // instead of a false-success "Saved" UI.
            throw $e;
        }
    }

    /**
     * Check if a product GID is a member of a Shopify collection.
     *
     * Read-path helper — returning false on Shopify failure is the safe
     * default (the toggle will look "off" in the UI, which is recoverable),
     * but we MUST log so the failure surfaces in monitoring instead of being
     * a silent "always-false" for a particular brand+collection.
     */
    private function isInCollection(array $metadata, string $handleKey, string $productGid, ProfessionalIntegration $integration): bool
    {
        $collectionHandle = Arr::get($metadata, $handleKey);
        if (empty($collectionHandle)) {
            return false;
        }

        // Use the storefront API to check membership — lightweight query
        // that returns just the product ID if present.
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $storefrontToken = trim((string) ($integration->storefront_token ?? ''));
        $apiVersion = config('services.shopify.api_version');

        if ($shopDomain === '' || $storefrontToken === '') {
            return false;
        }

        $query = <<<'GRAPHQL'
query collectionProduct($handle: String!, $productId: ID!) {
  collection(handle: $handle) {
    products(first: 1, query: $productId) {
      edges {
        node { id }
      }
    }
  }
}
GRAPHQL;

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders(['X-Shopify-Storefront-Access-Token' => $storefrontToken])
                ->post("https://{$shopDomain}/api/{$apiVersion}/graphql.json", [
                    'query' => $query,
                    'variables' => [
                        'handle' => $collectionHandle,
                        'productId' => $productGid,
                    ],
                ]);

            $data = $response->json();
            $edges = Arr::get($data, 'data.collection.products.edges', []);

            return is_array($edges) && count($edges) > 0;
        } catch (\Throwable $e) {
            Log::warning('Shopify Storefront API exception checking collection membership.', [
                'shop_domain' => $shopDomain,
                'collection_handle' => $collectionHandle,
                'product_gid' => $productGid,
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Save a metafield value for a product via Shopify Admin API.
     */
    private function saveMetafield(ProfessionalIntegration $integration, string $productGid, string $field, mixed $value): void
    {
        // Map field names to sidest namespace keys
        $key = match ($field) {
            'active' => 'active',
            'commission_override' => 'commission_override',
            'affiliate_discount_pct' => 'affiliate_discount_pct',
            'custom_photos_enabled' => 'custom_photos_enabled',
            'disabled_variant_gids' => 'disabled_variant_gids',
            default => throw new \InvalidArgumentException("Unknown metafield: {$field}"),
        };

        // For disabled_variant_gids, we need to update individual variant metafields.
        // The value is an array of variant GIDs to disable; all others are enabled.
        if ($field === 'disabled_variant_gids') {
            $this->saveVariantEnabledStates($integration, $productGid, is_array($value) ? $value : []);

            return;
        }

        $shopDomain = trim((string) Arr::get($integration->provider_metadata ?? [], 'shop_domain', ''));
        $adminToken = trim((string) ($integration->access_token ?? ''));
        $apiVersion = config('services.shopify.api_version');

        if ($shopDomain === '' || $adminToken === '') {
            throw new \RuntimeException('Shopify integration is missing credentials.');
        }

        // productUpdate with a metafields array upserts by (namespace, key) — no
        // prior findMetafield ID lookup needed (SCALE-5).
        $typedValue = match (true) {
            is_bool($value) => json_encode($value),
            is_numeric($value) => (string) $value,
            is_null($value) => '',
            default => (string) $value,
        };

        $type = match (true) {
            is_bool($value) => 'boolean',
            is_numeric($value) => 'number_decimal',
            default => 'single_line_text_field',
        };

        $mutation = <<<'GRAPHQL'
mutation setProductMetafield($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id }
    userErrors { field message }
  }
}
GRAPHQL;

        $result = Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $mutation,
                'variables' => ['input' => [
                    'id' => $productGid,
                    'metafields' => [[
                        'namespace' => 'partna',
                        'key' => $key,
                        'value' => $typedValue,
                        'type' => $type,
                    ]],
                ]],
            ]);

        $resultData = $result->json();
        if (! $result->successful()) {
            throw new \RuntimeException("Shopify API returned {$result->status()}");
        }

        $errors = Arr::get($resultData, 'data.productUpdate.userErrors', []);
        if (! empty($errors)) {
            $msg = is_array($errors) ? ($errors[0]['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException($msg);
        }
    }

    /**
     * Update variant enabled states for a product in a single Shopify call.
     *
     * Computes the diff between the variants Shopify currently reports and
     * the desired disabled-set, then issues ONE productVariantsBulkUpdate
     * mutation carrying the partna.enabled metafield for every variant that
     * changed (SCALE-3). Skipping the mutation entirely when nothing changed
     * keeps the typical "click Save with no toggles" path free of Shopify
     * round-trips beyond the variant fetch.
     */
    private function saveVariantEnabledStates(ProfessionalIntegration $integration, string $productGid, array $disabledGids): void
    {
        $variants = $this->fetchVariants($integration, $this->extractId($productGid));

        // Build the variants[] payload from the diff only — variants whose
        // state matches the desired state are omitted so the request stays
        // small and we can short-circuit when no work is needed.
        $changedVariants = [];
        foreach ($variants as $variant) {
            $shouldEnable = ! in_array($variant['gid'], $disabledGids, true);
            if ($variant['enabled'] === $shouldEnable) {
                continue;
            }
            $changedVariants[] = [
                'id' => $variant['gid'],
                'metafields' => [[
                    'namespace' => 'partna',
                    'key' => 'enabled',
                    'value' => $shouldEnable ? 'true' : 'false',
                    'type' => 'boolean',
                ]],
            ];
        }

        if ($changedVariants === []) {
            return;
        }

        $this->bulkUpdateVariantMetafields($integration, $productGid, $changedVariants);
    }

    /**
     * Issue a single productVariantsBulkUpdate mutation to write metafields
     * on the given set of variants. Surfaces network and userErrors as
     * exceptions so update() can map them to 422.
     *
     * @param  array<int, array{id: string, metafields: array<int, array<string, string>>}>  $variants
     */
    private function bulkUpdateVariantMetafields(ProfessionalIntegration $integration, string $productGid, array $variants): void
    {
        $shopDomain = trim((string) Arr::get($integration->provider_metadata ?? [], 'shop_domain', ''));
        $adminToken = trim((string) ($integration->access_token ?? ''));
        $apiVersion = config('services.shopify.api_version');

        if ($shopDomain === '' || $adminToken === '') {
            throw new \RuntimeException('Shopify integration is missing credentials.');
        }

        $mutation = <<<'GRAPHQL'
mutation bulkUpdateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id }
    userErrors { field message }
  }
}
GRAPHQL;

        $response = Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $mutation,
                'variables' => [
                    'productId' => $productGid,
                    'variants' => $variants,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Shopify API returned {$response->status()}");
        }

        $data = $response->json();
        $errors = Arr::get($data, 'data.productVariantsBulkUpdate.userErrors', []);
        if (! empty($errors)) {
            $msg = is_array($errors) ? ($errors[0]['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException($msg);
        }
    }

    /**
     * Add or remove a product from a manual Shopify collection.
     *
     * Collection-id resolution is delegated to BrandCatalogService::resolveCollectionGid,
     * which already caches the (shop_domain, handle) → GID lookup with the same
     * jitter/SWR helpers used elsewhere. Previously this method ran an inline
     * uncached collection(handle:){id} query on every toggle, costing one
     * Admin GraphQL call per click.
     */
    private function toggleCollection(ProfessionalIntegration $integration, string $productGid, string $handleKey, bool $add): void
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $collectionHandle = Arr::get($metadata, $handleKey);

        if (empty($collectionHandle)) {
            throw new \RuntimeException('Collection has not been created yet.');
        }

        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $adminToken = trim((string) ($integration->access_token ?? ''));
        $apiVersion = config('services.shopify.api_version');

        if ($shopDomain === '' || $adminToken === '') {
            throw new \RuntimeException('Shopify integration is missing credentials.');
        }

        $collectionId = $this->catalog->resolveCollectionGid($integration, (string) $collectionHandle);

        if (empty($collectionId)) {
            throw new \RuntimeException("Collection '{$collectionHandle}' not found on Shopify.");
        }

        if ($add) {
            $mutation = <<<'GRAPHQL'
mutation collectionAddProducts($id: ID!, $productIds: [ID!]!) {
  collectionAddProducts(id: $id, productIds: $productIds) {
    userErrors { field message }
  }
}
GRAPHQL;
        } else {
            $mutation = <<<'GRAPHQL'
mutation collectionRemoveProducts($id: ID!, $productIds: [ID!]!) {
  collectionRemoveProducts(id: $id, productIds: $productIds) {
    userErrors { field message }
  }
}
GRAPHQL;
        }

        $result = Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $mutation,
                'variables' => [
                    'id' => $collectionId,
                    'productIds' => [$productGid],
                ],
            ]);

        $resultData = $result->json();
        $errors = Arr::get($resultData, 'data.collectionAddProducts.userErrors', Arr::get($resultData, 'data.collectionRemoveProducts.userErrors', []));

        if (! empty($errors)) {
            $msg = is_array($errors) ? ($errors[0]['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException($msg);
        }
    }

    // ── Value parsers ────────────────────────────────────────────────────

    private function parseBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function parseFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

        return $parsed;
    }
}
