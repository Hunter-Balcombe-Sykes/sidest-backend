<?php

namespace App\Services\Store;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrandCatalogService
{
    private const CATALOG_CACHE_TTL_MINUTES = 10;

    private const COLLECTION_GID_CACHE_TTL_MINUTES = 60;

    private const PRODUCT_CUSTOM_PHOTOS_TTL_SECONDS = 60;

    private const PRODUCTS_PER_PAGE = 50;

    // --- GraphQL Queries & Mutations ---

    private const ALL_PRODUCTS = <<<'GRAPHQL'
query allProducts($first: Int!, $after: String) {
  products(first: $first, after: $after) {
    edges {
      node {
        id
        title
        handle
        status
        description
        featuredImage { url altText }
        images(first: 10) { edges { node { url altText } } }
        priceRange {
          minVariantPrice { amount currencyCode }
          maxVariantPrice { amount currencyCode }
        }
        # Variants + per-variant sidest.enabled state — needed by the brand
        # catalog table to render the expand chevron on multi-variant
        # products and the per-variant enable/disable toggle.
        #
        # Cap at 20 variants per product to stay under Shopify's GraphQL
        # 1000-cost budget (50 products × 100 variants overflows). 20 is
        # well above the practical max any brand is likely to ship on one
        # product — colour × size typically tops out at ~15. If a brand
        # ever needs more they can still fetch the full list via the
        # single-product queries elsewhere in this service.
        variants(first: 20) {
          edges {
            node {
              id
              title
              availableForSale
              price { amount currencyCode }
              metafield_enabled: metafield(namespace: "sidest", key: "enabled") { value }
            }
          }
        }
        metafield_active: metafield(namespace: "sidest", key: "active") { value }
        metafield_commission: metafield(namespace: "sidest", key: "commission_override") { value }
        metafield_discount: metafield(namespace: "sidest", key: "affiliate_discount_pct") { value }
        metafield_custom_photos: metafield(namespace: "sidest", key: "custom_photos_enabled") { value }
        metafield_has_enabled_variants: metafield(namespace: "sidest", key: "has_enabled_variants") { value }
      }
      cursor
    }
    pageInfo { hasNextPage }
  }
}
GRAPHQL;

    private const PRODUCTS_WITH_METAFIELDS = <<<'GRAPHQL'
query products($first: Int!, $after: String) {
  products(first: $first, after: $after) {
    edges {
      node {
        id
        title
        handle
        status
        featuredImage {
          url
          altText
        }
        priceRange {
          minVariantPrice { amount currencyCode }
          maxVariantPrice { amount currencyCode }
        }
        variants(first: 100) {
          edges {
            node {
              id
              title
              availableForSale
              price { amount currencyCode }
              metafield_enabled: metafield(namespace: "sidest", key: "enabled") { value }
            }
          }
        }
        metafield_active: metafield(namespace: "sidest", key: "active") { value }
        metafield_commission: metafield(namespace: "sidest", key: "commission_override") { value }
        metafield_discount: metafield(namespace: "sidest", key: "affiliate_discount_pct") { value }
        metafield_custom_photos: metafield(namespace: "sidest", key: "custom_photos_enabled") { value }
        metafield_has_enabled_variants: metafield(namespace: "sidest", key: "has_enabled_variants") { value }
      }
      cursor
    }
    pageInfo { hasNextPage }
  }
}
GRAPHQL;

    private const PRODUCT_VARIANT_GIDS = <<<'GRAPHQL'
query productVariantGids($productId: ID!) {
  product(id: $productId) {
    variants(first: 100) {
      edges { node { id } }
    }
  }
}
GRAPHQL;

    private const PRODUCT_CUSTOM_PHOTOS_QUERY = <<<'GRAPHQL'
query productCustomPhotos($productId: ID!) {
  product(id: $productId) {
    metafield(namespace: "sidest", key: "custom_photos_enabled") { value }
  }
}
GRAPHQL;

    private const METAFIELDS_SET = <<<'GRAPHQL'
mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { id namespace key value }
    userErrors { field message }
  }
}
GRAPHQL;

    private const METAFIELD_DELETE = <<<'GRAPHQL'
mutation metafieldDelete($input: MetafieldDeleteInput!) {
  metafieldDelete(input: $input) {
    deletedId
    userErrors { field message }
  }
}
GRAPHQL;

    private const PRODUCT_METAFIELD_ID = <<<'GRAPHQL'
query productMetafield($productId: ID!, $namespace: String!, $key: String!) {
  product(id: $productId) {
    metafield(namespace: $namespace, key: $key) { id }
  }
}
GRAPHQL;

    private const SHOP_ID_QUERY = '{ shop { id } }';

    private const COMMISSION_OVERRIDES_QUERY = <<<'GRAPHQL'
query commissionOverrides($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on Product {
      id
      metafield(namespace: "sidest", key: "commission_override") { value }
    }
  }
}
GRAPHQL;

    private const COLLECTIONS_QUERY = <<<'GRAPHQL'
query collections($query: String!, $first: Int!) {
  collections(query: $query, first: $first) {
    edges { node { id handle } }
  }
}
GRAPHQL;

    private const COLLECTION_PRODUCTS = <<<'GRAPHQL'
query collectionProducts($id: ID!, $first: Int!, $after: String) {
  collection(id: $id) {
    products(first: $first, after: $after) {
      edges {
        node {
          id
          title
          handle
          featuredImage { url altText }
          priceRange {
            minVariantPrice { amount currencyCode }
            maxVariantPrice { amount currencyCode }
          }
        }
        cursor
      }
      pageInfo { hasNextPage }
    }
  }
}
GRAPHQL;

    private const COLLECTION_ADD_PRODUCTS = <<<'GRAPHQL'
mutation collectionAddProducts($id: ID!, $productIds: [ID!]!) {
  collectionAddProducts(id: $id, productIds: $productIds) {
    collection { id }
    userErrors { field message }
  }
}
GRAPHQL;

    private const COLLECTION_REMOVE_PRODUCTS = <<<'GRAPHQL'
mutation collectionRemoveProducts($id: ID!, $productIds: [ID!]!) {
  collectionRemoveProducts(id: $id, productIds: $productIds) {
    job { id done }
    userErrors { field message }
  }
}
GRAPHQL;

    /**
     * Resolve the brand's Shopify integration credentials.
     *
     * @return array{integration: ProfessionalIntegration, shop_domain: string, access_token: string, metadata: array}
     *
     * @throws \RuntimeException
     */
    public function resolveBrandIntegration(Professional $brand): array
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brand->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            throw new \RuntimeException('Your Shopify store is not connected.', 422);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);

        if ($shopDomain === '' || $accessToken === '') {
            throw new \RuntimeException('Shopify integration is not fully configured.', 503);
        }

        return [
            'integration' => $integration,
            'shop_domain' => $shopDomain,
            'access_token' => $accessToken,
            'metadata' => $metadata,
        ];
    }

    /**
     * Fetch ALL products from the brand's Shopify store (no metafield dependencies).
     * Uses a lightweight query that only needs basic product read access.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllProducts(Professional $brand): array
    {
        return $this->queryAllProducts($brand);
    }

    /**
     * Fetch the brand's full product catalog with sidest.* metafield values (cached).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBrandCatalog(Professional $brand): array
    {
        return $this->queryAdminCatalog($brand);
    }

    /**
     * Fetch the sidest.commission_override metafield for a set of product GIDs
     * in a single Admin API call. Returns a map keyed by product GID; value is
     * the float override or null when the metafield is unset.
     *
     * Used by ProcessShopifyOrderWebhookJob to resolve commission rates
     * server-side instead of trusting buyer-set cart line attributes.
     *
     * @param  array<int, string>  $productGids
     * @return array<string, float|null>
     */
    public function fetchCommissionOverridesForProducts(ProfessionalIntegration $integration, array $productGids): array
    {
        $productGids = array_values(array_unique(array_filter($productGids)));
        if (empty($productGids)) {
            return [];
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);

        if ($shopDomain === '' || $accessToken === '') {
            return array_fill_keys($productGids, null);
        }

        try {
            $response = $this->graphql(
                $shopDomain,
                $accessToken,
                self::COMMISSION_OVERRIDES_QUERY,
                ['ids' => $productGids]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch commission overrides.', [
                'integration_id' => (string) $integration->id,
                'error' => $e->getMessage(),
            ]);

            return array_fill_keys($productGids, null);
        }

        $nodes = $response->json('data.nodes', []);
        $out = array_fill_keys($productGids, null);

        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $gid = (string) ($node['id'] ?? '');
                if ($gid === '') {
                    continue;
                }
                $val = Arr::get($node, 'metafield.value');
                $out[$gid] = $val !== null ? (float) $val : null;
            }
        }

        return $out;
    }

    /**
     * Fetch the per-product custom_photos_enabled metafield with a short cache.
     * Returns true/false if set, null if not set on the product.
     */
    public function fetchProductCustomPhotosMetafield(ProfessionalIntegration $integration, string $productGid): ?bool
    {
        // Cache as string sentinel so "unset" cases still benefit from the TTL
        // (Cache::remember treats a cached null as a miss and re-runs the closure).
        $cacheKey = CacheKeyGenerator::brandProductCustomPhotos((string) $integration->professional_id, $productGid);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return match ($cached) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }

        $resolved = $this->resolveCredentials($integration);

        try {
            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::PRODUCT_CUSTOM_PHOTOS_QUERY, [
                'productId' => $productGid,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch product custom_photos_enabled metafield.', [
                'brand_id' => (string) $integration->professional_id,
                'product_gid' => $productGid,
                'error' => $e->getMessage(),
            ]);

            Cache::put($cacheKey, 'unset', now()->addSeconds(self::PRODUCT_CUSTOM_PHOTOS_TTL_SECONDS));

            return null;
        }

        $value = Arr::get($response->json(), 'data.product.metafield.value');

        if ($value === null) {
            Cache::put($cacheKey, 'unset', now()->addSeconds(self::PRODUCT_CUSTOM_PHOTOS_TTL_SECONDS));

            return null;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        Cache::put($cacheKey, $bool ? 'true' : 'false', now()->addSeconds(self::PRODUCT_CUSTOM_PHOTOS_TTL_SECONDS));

        return $bool;
    }

    /**
     * Fetch the full set of variant GIDs for a single product. Used by the write path
     * to verify brand-submitted disabled_variant_gids only contains GIDs that
     * actually belong to this product. One GraphQL call regardless of catalog size.
     *
     * Limited to the first 100 variants — the realistic per-product cap. Raise the
     * limit here if a brand ever ships a product with more than 100 variants.
     *
     * @return array<int, string> Variant GID strings, e.g. ["gid://shopify/ProductVariant/1", ...]
     */
    public function fetchProductVariantGids(ProfessionalIntegration $integration, string $productGid): array
    {
        $resolved = $this->resolveCredentials($integration);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::PRODUCT_VARIANT_GIDS, [
            'productId' => $productGid,
        ]);

        $edges = Arr::get($response->json(), 'data.product.variants.edges', []);

        if (! is_array($edges)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($edge) => Arr::get($edge, 'node.id'),
            $edges
        )));
    }

    /**
     * Set variant-level sidest.enabled metafields. Writes `false` on disabled variants
     * and `true` on all others to clear any previous restriction. Uses a single
     * metafieldsSet mutation (batched at 25 if the product has many variants).
     *
     * Also recomputes and writes sidest.has_enabled_variants on the parent product:
     * true if at least one variant is not disabled, false if every variant is
     * disabled. This drives the Active Products smart collection condition so a
     * product with no enabled variants falls out of affiliate-facing surfaces
     * automatically, without the brand having to flip sidest.active themselves.
     *
     * @param  string  $productGid  Parent product GID (used to write the derived product metafield)
     * @param  array<int, string>  $allVariantGids  Every variant GID for the product (pre-fetched for validation)
     * @param  array<int, string>  $disabledVariantGids  Variant GIDs to mark as disabled
     * @return array{success: bool, userErrors: array}
     */
    public function setVariantEnabledStates(ProfessionalIntegration $integration, string $productGid, array $allVariantGids, array $disabledVariantGids = []): array
    {
        if (empty($allVariantGids)) {
            // No variants on this product — has_enabled_variants is trivially true.
            // Write it anyway so newly created products get an explicit value and the
            // Active Products smart collection picks them up.
            return $this->writeHasEnabledVariants($integration, $productGid, true);
        }

        $disabled = array_flip($disabledVariantGids);
        $resolved = $this->resolveCredentials($integration);

        $metafields = array_map(fn (string $variantGid) => [
            'namespace' => 'sidest',
            'key' => 'enabled',
            'value' => isset($disabled[$variantGid]) ? 'false' : 'true',
            'type' => 'boolean',
            'ownerId' => $variantGid,
        ], $allVariantGids);

        // Shopify limits metafieldsSet to 25 inputs per call
        foreach (array_chunk($metafields, 25) as $batch) {
            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
                'metafields' => $batch,
            ]);

            $userErrors = Arr::get($response->json(), 'data.metafieldsSet.userErrors', []);

            if (! empty($userErrors)) {
                return ['success' => false, 'userErrors' => $userErrors];
            }
        }

        // Bust caches — variant state affects both brand and affiliate views
        Cache::forget(CacheKeyGenerator::brandAdminCatalog((string) $integration->professional_id));
        Cache::forget(CacheKeyGenerator::brandActiveCatalog((string) $integration->professional_id));

        // Recompute and persist the derived product-level flag
        $hasEnabled = count($disabledVariantGids) < count($allVariantGids);

        return $this->writeHasEnabledVariants($integration, $productGid, $hasEnabled);
    }

    /**
     * Write the derived sidest.has_enabled_variants metafield on a product. Separated
     * out so the backfill command can call it with a precomputed value without
     * re-writing every variant metafield.
     *
     * @return array{success: bool, userErrors: array}
     */
    public function writeHasEnabledVariants(ProfessionalIntegration $integration, string $productGid, bool $value): array
    {
        $resolved = $this->resolveCredentials($integration);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
            'metafields' => [[
                'namespace' => 'sidest',
                'key' => 'has_enabled_variants',
                'value' => $value ? 'true' : 'false',
                'type' => 'boolean',
                'ownerId' => $productGid,
            ]],
        ]);

        $userErrors = Arr::get($response->json(), 'data.metafieldsSet.userErrors', []);

        // Writing has_enabled_variants moves the product in/out of the Active Products
        // smart collection, so the affiliate-facing cache must be invalidated too.
        Cache::forget(CacheKeyGenerator::brandAdminCatalog((string) $integration->professional_id));
        Cache::forget(CacheKeyGenerator::brandActiveCatalog((string) $integration->professional_id));

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Set metafield values on a product.
     *
     * @param  array  $metafields  e.g. [['key' => 'active', 'value' => 'true', 'type' => 'boolean'], ...]
     * @return array{success: bool, userErrors: array}
     */
    public function setProductMetafields(ProfessionalIntegration $integration, string $productGid, array $metafields): array
    {
        $resolved = $this->resolveCredentials($integration);

        $metafieldsInput = array_map(fn (array $mf) => [
            'namespace' => 'sidest',
            'key' => $mf['key'],
            'value' => (string) $mf['value'],
            'type' => $mf['type'],
            'ownerId' => $productGid,
        ], $metafields);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
            'metafields' => $metafieldsInput,
        ]);

        $data = $response->json();
        $userErrors = Arr::get($data, 'data.metafieldsSet.userErrors', []);

        // Bust caches
        $this->bustCatalogCaches($integration, $metafields, $productGid);

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Delete a metafield from a product (for clearing overrides).
     */
    public function deleteProductMetafield(ProfessionalIntegration $integration, string $productGid, string $key): bool
    {
        $resolved = $this->resolveCredentials($integration);

        // First get the metafield ID
        $idResponse = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::PRODUCT_METAFIELD_ID, [
            'productId' => $productGid,
            'namespace' => 'sidest',
            'key' => $key,
        ]);

        $metafieldId = Arr::get($idResponse->json(), 'data.product.metafield.id');

        if (! $metafieldId) {
            return true; // Already doesn't exist
        }

        $deleteResponse = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELD_DELETE, [
            'input' => ['id' => $metafieldId],
        ]);

        $userErrors = Arr::get($deleteResponse->json(), 'data.metafieldDelete.userErrors', []);

        // Bust caches
        Cache::forget(CacheKeyGenerator::brandAdminCatalog((string) $integration->professional_id));

        if ($key === 'custom_photos_enabled') {
            Cache::forget(CacheKeyGenerator::brandProductCustomPhotos((string) $integration->professional_id, $productGid));
        }

        return empty($userErrors);
    }

    /**
     * Set shop-level metafields.
     *
     * @param  array  $metafields  e.g. [['key' => 'default_commission_rate', 'value' => '15', 'type' => 'number_decimal'], ...]
     * @return array{success: bool, userErrors: array}
     */
    public function setShopMetafields(ProfessionalIntegration $integration, array $metafields): array
    {
        $resolved = $this->resolveCredentials($integration);

        // Get shop GID
        $shopResponse = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::SHOP_ID_QUERY, []);
        $shopGid = Arr::get($shopResponse->json(), 'data.shop.id', '');

        if ($shopGid === '') {
            return ['success' => false, 'userErrors' => [['message' => 'Could not resolve shop ID.']]];
        }

        $metafieldsInput = array_map(fn (array $mf) => [
            'namespace' => 'sidest',
            'key' => $mf['key'],
            'value' => (string) $mf['value'],
            'type' => $mf['type'],
            'ownerId' => $shopGid,
        ], $metafields);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::METAFIELDS_SET, [
            'metafields' => $metafieldsInput,
        ]);

        $userErrors = Arr::get($response->json(), 'data.metafieldsSet.userErrors', []);

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Resolve collection GID from handle (cached).
     */
    public function resolveCollectionGid(ProfessionalIntegration $integration, string $handle): ?string
    {
        $cacheKey = CacheKeyGenerator::brandCollectionGid((string) $integration->professional_id, $handle);

        return Cache::remember($cacheKey, now()->addMinutes(self::COLLECTION_GID_CACHE_TTL_MINUTES), function () use ($integration, $handle) {
            $resolved = $this->resolveCredentials($integration);

            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTIONS_QUERY, [
                'query' => "handle:{$handle}",
                'first' => 1,
            ]);

            return Arr::get($response->json(), 'data.collections.edges.0.node.id');
        });
    }

    /**
     * Fetch products from a collection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCollectionProducts(ProfessionalIntegration $integration, string $collectionGid): array
    {
        $resolved = $this->resolveCredentials($integration);
        $products = [];
        $cursor = null;

        do {
            $variables = ['id' => $collectionGid, 'first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_PRODUCTS, $variables);
            $data = $response->json();

            $edges = Arr::get($data, 'data.collection.products.edges', []);
            if (! is_array($edges)) {
                break;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $cursor = $edge['cursor'] ?? null;

                $products[] = [
                    'gid' => $node['id'] ?? '',
                    'title' => $node['title'] ?? '',
                    'handle' => $node['handle'] ?? '',
                    'featured_image' => $node['featuredImage'] ?? null,
                    'price_range' => [
                        'min' => Arr::get($node, 'priceRange.minVariantPrice'),
                        'max' => Arr::get($node, 'priceRange.maxVariantPrice'),
                    ],
                ];
            }

            $hasNextPage = Arr::get($data, 'data.collection.products.pageInfo.hasNextPage', false);
        } while ($hasNextPage && $cursor !== null);

        return $products;
    }

    /**
     * Add products to a manual collection.
     *
     * @return array{success: bool, userErrors: array}
     */
    public function addProductsToCollection(ProfessionalIntegration $integration, string $collectionGid, array $productGids): array
    {
        $resolved = $this->resolveCredentials($integration);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_ADD_PRODUCTS, [
            'id' => $collectionGid,
            'productIds' => $productGids,
        ]);

        $userErrors = Arr::get($response->json(), 'data.collectionAddProducts.userErrors', []);

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    /**
     * Remove products from a manual collection.
     *
     * @return array{success: bool, userErrors: array}
     */
    public function removeProductsFromCollection(ProfessionalIntegration $integration, string $collectionGid, array $productGids): array
    {
        $resolved = $this->resolveCredentials($integration);

        $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_REMOVE_PRODUCTS, [
            'id' => $collectionGid,
            'productIds' => $productGids,
        ]);

        $userErrors = Arr::get($response->json(), 'data.collectionRemoveProducts.userErrors', []);

        return [
            'success' => empty($userErrors),
            'userErrors' => $userErrors,
        ];
    }

    // --- Private Helpers ---

    /**
     * @return array{shop_domain: string, access_token: string}
     */
    private function resolveCredentials(ProfessionalIntegration $integration): array
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        return [
            'shop_domain' => trim((string) Arr::get($metadata, 'shop_domain', '')),
            'access_token' => trim((string) $integration->access_token),
        ];
    }

    private function graphql(string $shopDomain, string $accessToken, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        $apiVersion = config('services.shopify.api_version', '2025-01');

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", array_filter([
                'query' => $query,
                'variables' => ! empty($variables) ? $variables : null,
            ]));

        if (! $response->successful()) {
            Log::warning('Shopify Admin API request failed.', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            throw new \RuntimeException('Unable to reach Shopify. Please try again.', 502);
        }

        $errors = Arr::get($response->json(), 'errors', []);
        if (! empty($errors)) {
            Log::warning('Shopify Admin API returned errors.', [
                'shop_domain' => $shopDomain,
                'errors' => $errors,
            ]);

            throw new \RuntimeException('Unable to reach Shopify. Please try again.', 502);
        }

        return $response;
    }

    /**
     * Bust relevant caches after a metafield write.
     */
    private function bustCatalogCaches(ProfessionalIntegration $integration, array $metafields, ?string $productGid = null): void
    {
        $brandId = (string) $integration->professional_id;

        // Always bust the admin catalog cache
        Cache::forget(CacheKeyGenerator::brandAdminCatalog($brandId));

        $touchedKeys = array_column($metafields, 'key');

        // If 'active' was changed, also bust the affiliate-facing catalog cache
        if (in_array('active', $touchedKeys, true)) {
            Cache::forget(CacheKeyGenerator::brandActiveCatalog($brandId));
        }

        // If per-product custom_photos_enabled was changed, bust the targeted lookup cache
        if ($productGid !== null && in_array('custom_photos_enabled', $touchedKeys, true)) {
            Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($brandId, $productGid));
        }
    }

    /**
     * Query Admin API for all products with sidest metafield values.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryAdminCatalog(Professional $brand): array
    {
        $resolved = $this->resolveBrandIntegration($brand);
        $shopDomain = $resolved['shop_domain'];
        $accessToken = $resolved['access_token'];

        $products = [];
        $cursor = null;

        do {
            $variables = ['first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            try {
                $response = $this->graphql($shopDomain, $accessToken, self::PRODUCTS_WITH_METAFIELDS, $variables);
                $data = $response->json();

                // Log GraphQL errors if present
                if (! empty($data['errors'])) {
                    Log::error('Shopify catalog GraphQL errors.', [
                        'brand_id' => (string) $brand->id,
                        'errors' => $data['errors'],
                    ]);
                }

                $edges = Arr::get($data, 'data.products.edges', []);
                if (! is_array($edges)) {
                    Log::warning('Shopify catalog returned no edges.', [
                        'brand_id' => (string) $brand->id,
                        'response_keys' => array_keys($data ?? []),
                    ]);
                    break;
                }

                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    $cursor = $edge['cursor'] ?? null;

                    $variants = [];
                    foreach (Arr::get($node, 'variants.edges', []) as $variantEdge) {
                        $v = $variantEdge['node'] ?? [];
                        $enabledVal = Arr::get($v, 'metafield_enabled.value');
                        $variants[] = [
                            'gid' => $v['id'] ?? '',
                            'title' => $v['title'] ?? '',
                            'available_for_sale' => $v['availableForSale'] ?? false,
                            'price' => $v['price'] ?? null,
                            'enabled' => $enabledVal !== null ? filter_var($enabledVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ];
                    }

                    $activeVal = Arr::get($node, 'metafield_active.value');
                    $commissionVal = Arr::get($node, 'metafield_commission.value');
                    $discountVal = Arr::get($node, 'metafield_discount.value');
                    $customPhotosVal = Arr::get($node, 'metafield_custom_photos.value');
                    $hasEnabledVariantsVal = Arr::get($node, 'metafield_has_enabled_variants.value');

                    $products[] = [
                        'gid' => $node['id'] ?? '',
                        'title' => $node['title'] ?? '',
                        'handle' => $node['handle'] ?? '',
                        'status' => $node['status'] ?? 'ACTIVE',
                        'featured_image' => $node['featuredImage'] ?? null,
                        'price_range' => [
                            'min' => Arr::get($node, 'priceRange.minVariantPrice'),
                            'max' => Arr::get($node, 'priceRange.maxVariantPrice'),
                        ],
                        'variants' => $variants,
                        'metafields' => [
                            'active' => $activeVal !== null ? filter_var($activeVal, FILTER_VALIDATE_BOOLEAN) : null,
                            'commission_override' => $commissionVal !== null ? (float) $commissionVal : null,
                            'affiliate_discount_pct' => $discountVal !== null ? (float) $discountVal : null,
                            'custom_photos_enabled' => $customPhotosVal !== null ? filter_var($customPhotosVal, FILTER_VALIDATE_BOOLEAN) : null,
                            // null = unwritten (pre-backfill); true/false = explicit. The backfill
                            // command populates this for existing products; new writes go through
                            // writeHasEnabledVariants on every variant-state change.
                            'has_enabled_variants' => $hasEnabledVariantsVal !== null ? filter_var($hasEnabledVariantsVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ],
                    ];
                }

                $hasNextPage = Arr::get($data, 'data.products.pageInfo.hasNextPage', false);
            } catch (\Throwable $e) {
                Log::error('Failed to fetch brand admin catalog.', [
                    'brand_id' => (string) $brand->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while ($hasNextPage && $cursor !== null);

        return $products;
    }

    /**
     * Query Admin API for all products without metafield dependencies.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryAllProducts(Professional $brand): array
    {
        $resolved = $this->resolveBrandIntegration($brand);
        $shopDomain = $resolved['shop_domain'];
        $accessToken = $resolved['access_token'];

        $products = [];
        $cursor = null;

        do {
            $variables = ['first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            try {
                $response = $this->graphql($shopDomain, $accessToken, self::ALL_PRODUCTS, $variables);
                $data = $response->json();

                if (! empty($data['errors'])) {
                    Log::error('Shopify all-products GraphQL errors.', [
                        'brand_id' => (string) $brand->id,
                        'errors' => $data['errors'],
                    ]);
                }

                $edges = Arr::get($data, 'data.products.edges', []);
                if (! is_array($edges)) {
                    break;
                }

                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    $cursor = $edge['cursor'] ?? null;

                    $activeVal = Arr::get($node, 'metafield_active.value');
                    $commissionVal = Arr::get($node, 'metafield_commission.value');
                    $discountVal = Arr::get($node, 'metafield_discount.value');
                    $customPhotosVal = Arr::get($node, 'metafield_custom_photos.value');
                    $hasEnabledVariantsVal = Arr::get($node, 'metafield_has_enabled_variants.value');

                    // Map product images from GraphQL edges
                    $images = array_map(
                        fn ($imgEdge) => $imgEdge['node'] ?? null,
                        Arr::get($node, 'images.edges', [])
                    );
                    $images = array_values(array_filter($images));

                    // Variants + per-variant sidest.enabled flag. Null enabled
                    // means the metafield is absent (dynamic default = enabled);
                    // the frontend treats null as "not explicitly disabled".
                    $variants = [];
                    foreach (Arr::get($node, 'variants.edges', []) as $variantEdge) {
                        $v = $variantEdge['node'] ?? [];
                        $enabledVal = Arr::get($v, 'metafield_enabled.value');
                        $variants[] = [
                            'gid' => $v['id'] ?? '',
                            'title' => $v['title'] ?? '',
                            'available_for_sale' => $v['availableForSale'] ?? false,
                            'price' => $v['price'] ?? null,
                            'enabled' => $enabledVal !== null ? filter_var($enabledVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ];
                    }

                    $products[] = [
                        'gid' => $node['id'] ?? '',
                        'title' => $node['title'] ?? '',
                        'handle' => $node['handle'] ?? '',
                        'status' => $node['status'] ?? 'ACTIVE',
                        'description' => $node['description'] ?? '',
                        'featured_image' => $node['featuredImage'] ?? null,
                        'images' => $images,
                        'price_range' => [
                            'min' => Arr::get($node, 'priceRange.minVariantPrice'),
                            'max' => Arr::get($node, 'priceRange.maxVariantPrice'),
                        ],
                        'variants' => $variants,
                        'metafields' => [
                            'active' => $activeVal !== null ? filter_var($activeVal, FILTER_VALIDATE_BOOLEAN) : null,
                            'commission_override' => $commissionVal !== null ? (float) $commissionVal : null,
                            'affiliate_discount_pct' => $discountVal !== null ? (float) $discountVal : null,
                            'custom_photos_enabled' => $customPhotosVal !== null ? filter_var($customPhotosVal, FILTER_VALIDATE_BOOLEAN) : null,
                            'has_enabled_variants' => $hasEnabledVariantsVal !== null ? filter_var($hasEnabledVariantsVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ],
                    ];
                }

                $hasNextPage = Arr::get($data, 'data.products.pageInfo.hasNextPage', false);
            } catch (\Throwable $e) {
                Log::error('Failed to fetch all products.', [
                    'brand_id' => (string) $brand->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while ($hasNextPage && $cursor !== null);

        return $products;
    }
}
