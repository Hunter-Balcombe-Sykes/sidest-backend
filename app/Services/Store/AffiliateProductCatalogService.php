<?php

namespace App\Services\Store;

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AffiliateProductCatalogService
{
    public function __construct(
        private readonly BrandCatalogService $brandCatalogService
    ) {}

    private const ADMIN_PRODUCTS_PER_PAGE = 50;

    // Admin API queries (we switched from Storefront API because custom-app
    // storefront tokens are scoped to Online Store publication only — they
    // can't see products published only to Hydrogen sales channels, which is
    // where every Partna brand publishes their catalog). Admin API uses the
    // brand's regular `access_token` and can read every publication.
    //
    // Shape mirrors BrandCatalogService's proven Admin-API queries:
    //   - Product.availableForSale is not in Admin API; we derive it from
    //     variant availability in PHP (any variant available_for_sale = true).
    //   - ProductVariant.price is a `Money` scalar (string like "29.99"),
    //     not MoneyV2 — no subselection. Currency comes from the parent
    //     product's priceRange.minVariantPrice.
    //   - Field `collectionByHandle(handle:)` works in Admin API (still
    //     supported as of 2025-01).
    private const COLLECTION_PRODUCTS_QUERY = <<<'GRAPHQL'
query collectionProducts($handle: String!, $first: Int!, $after: String) {
  collectionByHandle(handle: $handle) {
    products(first: $first, after: $after) {
      edges {
        node {
          id
          title
          handle
          description
          status
          featuredImage {
            url
            altText
          }
          # Gallery for the affiliate detail modal — up to 5 images per
          # product stays well under per-query complexity budgets and covers
          # every realistic product page. featuredImage stays separate
          # because it's the only one rendered on the card.
          images(first: 5) {
            edges {
              node {
                url
                altText
              }
            }
          }
          priceRange {
            minVariantPrice {
              amount
              currencyCode
            }
            maxVariantPrice {
              amount
              currencyCode
            }
          }
          variants(first: 5) {
            edges {
              node {
                id
                title
                availableForSale
                price
                metafield(namespace: "sidest", key: "enabled") { value }
              }
            }
          }
        }
        cursor
      }
      pageInfo {
        hasNextPage
      }
    }
  }
}
GRAPHQL;

    // All-products fallback query — used when the active collection doesn't exist
    // on Shopify (e.g. setup pipeline failed). Queries the products() root field
    // instead of collectionByHandle(handle: …), so it works without any smart
    // collection. Response shape identical to COLLECTION_PRODUCTS_QUERY except
    // the path is data.products instead of data.collectionByHandle.products.
    private const ALL_PRODUCTS_QUERY = <<<'GRAPHQL'
query allProducts($first: Int!, $after: String) {
  products(first: $first, after: $after) {
    edges {
      node {
        id
        title
        handle
        description
        status
        featuredImage {
          url
          altText
        }
        images(first: 5) {
          edges {
            node {
              url
              altText
            }
          }
        }
        priceRange {
          minVariantPrice {
            amount
            currencyCode
          }
          maxVariantPrice {
            amount
            currencyCode
          }
        }
        variants(first: 5) {
          edges {
            node {
              id
              title
              availableForSale
              price
              metafield(namespace: "sidest", key: "enabled") { value }
            }
          }
        }
      }
      cursor
    }
    pageInfo {
      hasNextPage
    }
  }
}
GRAPHQL;

    /**
     * Resolve the affiliate's connected brand and its Shopify integration.
     *
     * @return array{brand_professional_id: string, integration: ProfessionalIntegration}
     *
     * @throws \RuntimeException
     */
    public function resolveAffiliateBrandIntegration(Professional $affiliate): array
    {
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->first();

        if (! $link) {
            throw new \RuntimeException('No brand connection found.', 404);
        }

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $link->brand_professional_id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            throw new \RuntimeException('Brand does not have a connected Shopify store.', 422);
        }

        // Admin-API switch: we now use access_token (not storefront_token) so the
        // catalog read can see products published to any sales channel, not just
        // Online Store. See queryAdminCatalog() for full rationale.
        if (trim((string) ($integration->access_token ?? '')) === '') {
            throw new \RuntimeException('Shopify integration is not yet configured. Please try again shortly.', 503);
        }

        return [
            'brand_professional_id' => (string) $link->brand_professional_id,
            'integration' => $integration,
        ];
    }

    /**
     * Fetch the brand's active product catalog from Shopify Admin API (cached).
     *
     * Uses Admin API (not Storefront API) because custom-app storefront tokens
     * are scoped to the Online Store publication only, so they can't see
     * products that brands publish to Hydrogen sales channels — which is where
     * every Partna brand's catalog actually lives.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchActiveCatalog(string $brandProfessionalId): array
    {
        return Cache::memo()->remember(
            CacheKeyGenerator::brandActiveCatalog($brandProfessionalId),
            now()->addMinutes(5),
            fn () => $this->queryAdminCatalog($brandProfessionalId),
        );
    }

    /**
     * Seed the affiliate's product selections from the brand's default collection.
     * Existing selections are preserved; only missing defaults are added.
     * Called when an affiliate connects to a brand, and on explicit reset requests.
     *
     * @param  bool  $clearExisting  If true, clear all existing selections before seeding
     */
    public function seedDefaultSelections(Professional $affiliate, string $brandProfessionalId, bool $clearExisting = false): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return;
        }

        $defaultGids = $this->fetchCollectionGids($integration, 'default_collection_handle');

        if (empty($defaultGids)) {
            return;
        }

        if ($clearExisting) {
            AffiliateProductSelection::query()
                ->where('affiliate_professional_id', $affiliate->id)
                ->where('brand_professional_id', $brandProfessionalId)
                ->delete();
        }

        // Get already-selected GIDs so we don't create duplicates
        $existingGids = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('shopify_product_gid')
            ->all();

        $maxSort = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandProfessionalId)
            ->max('sort_order') ?? -1;

        foreach ($defaultGids as $gid) {
            if (in_array($gid, $existingGids, true)) {
                continue;
            }

            $maxSort++;
            AffiliateProductSelection::create([
                'affiliate_professional_id' => $affiliate->id,
                'brand_professional_id' => $brandProfessionalId,
                'shopify_product_gid' => $gid,
                'sort_order' => $maxSort,
            ]);
        }
    }

    /**
     * Return the brand's catalog with the affiliate's selection state and metafield data merged in.
     *
     * @return array{products: array, brand_professional_id: string, default_commission_rate: float, custom_photos_enabled: bool, product_image_ratio: string|null}
     */
    public function getCatalogWithSelections(Professional $affiliate): array
    {
        $resolved = $this->resolveAffiliateBrandIntegration($affiliate);
        $brandId = $resolved['brand_professional_id'];

        $integration = $resolved['integration'];
        $catalog = $this->fetchActiveCatalog($brandId);

        // Fetch brand product metafields (commission, discount) via Admin API
        $metafieldMap = $this->fetchBrandMetafieldMap($brandId);

        // Fetch favourites collection membership for filter support
        $favouritesGids = $this->fetchCollectionGids($integration, 'favourites_collection_handle');

        // Look up the brand's default commission rate from store settings
        $storeSettings = BrandStoreSettings::where('professional_id', $brandId)->first();
        $defaultCommissionRate = $storeSettings
            ? (float) $storeSettings->default_commission_rate
            : (float) config('partna.store.default_commission_rate', 15);

        // Brand-level product image ratio from site settings design JSON
        $site = Site::where('professional_id', $brandId)->first();
        $siteSettings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];
        $productImageRatio = $design['product_image_ratio'] ?? null;

        // Global custom photos toggle — brand-level permission for this affiliate
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandId)
            ->first();
        $customPhotosEnabled = (bool) ($link?->custom_photos_enabled ?? false);

        $selections = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandId)
            ->get()
            ->keyBy('shopify_product_gid');

        $products = array_map(function (array $product) use ($selections, $metafieldMap, $favouritesGids) {
            $gid = $product['gid'] ?? '';
            $selection = $selections->get($gid);

            $product['selected'] = $selection !== null;
            $product['sort_order'] = $selection?->sort_order;
            // Pass the affiliate's explicit variant subset through to the UI so the
            // variant picker can show current state. Null = no override (default-all).
            $product['selected_variant_gids'] = $selection?->selected_variant_gids;

            // Merge metafield data from Admin API
            $meta = $metafieldMap[$gid] ?? [];
            $product['commission_override'] = $meta['commission_override'] ?? null;
            $product['affiliate_discount_pct'] = $meta['affiliate_discount_pct'] ?? null;
            $product['custom_photos_enabled'] = $meta['custom_photos_enabled'];

            // Two-layer variant filter, in order:
            //   1. Brand-side: variants with sidest.enabled=false are hidden. Missing
            //      metafield (null) or true = available (dynamic default).
            //   2. Affiliate-side: if selected_variant_gids is populated, only those
            //      survive. If null, all brand-enabled variants remain.
            // Brand disables always win; the intersection guarantees an affiliate
            // never surfaces a variant the brand has taken down.
            $brandEnabledVariants = array_values(array_filter(
                $product['variants'] ?? [],
                fn (array $variant) => ($variant['enabled'] ?? null) !== false
            ));

            $affiliatePicks = $selection?->selected_variant_gids;
            if (is_array($affiliatePicks) && ! empty($affiliatePicks)) {
                $picked = array_flip($affiliatePicks);
                $product['variants'] = array_values(array_filter(
                    $brandEnabledVariants,
                    fn (array $v) => isset($picked[$v['gid'] ?? ''])
                ));
            } else {
                $product['variants'] = $brandEnabledVariants;
            }

            // Collection membership flags
            $product['in_favourites'] = in_array($gid, $favouritesGids, true);

            return $product;
        }, $catalog);

        // Stale recommendation: never serve a selection whose product or every chosen
        // variant has disappeared. The UI still sees them via getStaleSelections() and
        // can prompt a cleanup, but the storefront-facing view is already clean.
        $products = array_values(array_filter(
            $products,
            function (array $p) {
                if (! ($p['selected'] ?? false)) {
                    // Non-selected products always stay visible — the affiliate is browsing.
                    return true;
                }

                // Selected products only disappear from the catalog when the selection
                // has been explicitly narrowed to variants that no longer exist. An
                // unnarrowed selection with zero brand-enabled variants is a brand-side
                // stale state, surfaced through getStaleSelections().
                $picks = $p['selected_variant_gids'] ?? null;

                return ! (is_array($picks) && ! empty($picks) && empty($p['variants']));
            }
        ));

        return [
            'products' => $products,
            'brand_professional_id' => $brandId,
            'default_commission_rate' => $defaultCommissionRate,
            'custom_photos_enabled' => $customPhotosEnabled,
            'product_image_ratio' => $productImageRatio,
        ];
    }

    /**
     * Return selections whose product has disappeared from the brand's active catalog,
     * OR whose every brand-enabled variant has been disabled, OR whose explicit
     * affiliate-picked variants are now fully disabled by the brand. The frontend
     * surfaces these for affiliate-side cleanup.
     */
    public function getStaleSelections(Professional $affiliate): Collection
    {
        $resolved = $this->resolveAffiliateBrandIntegration($affiliate);
        $brandId = $resolved['brand_professional_id'];
        $catalog = $this->fetchActiveCatalog($brandId);

        // Build a lookup of enabled variant GIDs per product once, then test each
        // selection against it.
        $enabledVariantsByProduct = [];
        foreach ($catalog as $product) {
            $gid = $product['gid'] ?? '';
            if ($gid === '') {
                continue;
            }
            $enabledVariantsByProduct[$gid] = array_map(
                fn (array $v) => $v['gid'] ?? '',
                array_filter(
                    $product['variants'] ?? [],
                    fn (array $v) => ($v['enabled'] ?? null) !== false
                )
            );
        }

        return AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandId)
            ->get()
            ->filter(function (AffiliateProductSelection $sel) use ($enabledVariantsByProduct) {
                // Product no longer in active catalog (archived, deactivated, etc.)
                if (! isset($enabledVariantsByProduct[$sel->shopify_product_gid])) {
                    return true;
                }

                $enabledVariants = $enabledVariantsByProduct[$sel->shopify_product_gid];

                // Product still exists but every variant is disabled — stale.
                // (Products with no variants at all have an empty list too; those
                // are single-SKU products where the "variant" is implicit and
                // shouldn't be flagged. The brand-side has_enabled_variants flag
                // already captures this distinction, but since we don't have it
                // locally yet we keep the simpler rule for now: a selection is
                // stale only if the brand *had* variants and disabled them all.)
                $catalogVariantCount = count($enabledVariantsByProduct[$sel->shopify_product_gid]);
                if ($catalogVariantCount === 0) {
                    // Could be "no variants on product" (fine) or "all disabled"
                    // (stale). Without distinguishing, err toward not-stale so
                    // simple single-SKU products keep working.
                    return false;
                }

                // Affiliate has narrowed to specific variants — stale if every
                // chosen variant is no longer enabled.
                $picks = $sel->selected_variant_gids;
                if (is_array($picks) && ! empty($picks)) {
                    $stillValid = array_intersect($picks, $enabledVariants);

                    return empty($stillValid);
                }

                return false;
            });
    }

    /**
     * Check if a product GID exists in the brand's active catalog.
     */
    public function isProductInCatalog(string $brandProfessionalId, string $productGid): bool
    {
        $catalog = $this->fetchActiveCatalog($brandProfessionalId);

        return collect($catalog)->contains('gid', $productGid);
    }

    /**
     * Return the list of variant GIDs for a product that are currently brand-enabled
     * (i.e. variants with sidest.enabled != false). Used by the selection variants
     * endpoint to validate affiliate-submitted variant picks without trusting the
     * client. Returns an empty array if the product isn't in the active catalog.
     *
     * @return array<int, string>
     */
    public function getEnabledVariantGidsForProduct(string $brandProfessionalId, string $productGid): array
    {
        $catalog = $this->fetchActiveCatalog($brandProfessionalId);

        foreach ($catalog as $product) {
            if (($product['gid'] ?? '') !== $productGid) {
                continue;
            }

            return array_values(array_map(
                fn (array $v) => $v['gid'] ?? '',
                array_filter(
                    $product['variants'] ?? [],
                    fn (array $v) => ($v['enabled'] ?? null) !== false
                )
            ));
        }

        return [];
    }

    /**
     * Fetch product GIDs from a brand collection (e.g. favourites) using the Admin API.
     *
     * @param  string  $metadataKey  The key in provider_metadata for the collection handle
     * @return array<int, string> List of product GIDs in the collection
     */
    private function fetchCollectionGids(ProfessionalIntegration $integration, string $metadataKey): array
    {
        try {
            $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
            $handle = trim((string) Arr::get($metadata, $metadataKey, ''));

            if ($handle === '') {
                return [];
            }

            $collectionGid = $this->brandCatalogService->resolveCollectionGid($integration, $handle);

            if (! $collectionGid) {
                return [];
            }

            $products = $this->brandCatalogService->fetchCollectionProducts($integration, $collectionGid);

            return array_map(fn (array $p) => $p['gid'] ?? '', $products);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch collection GIDs for affiliate catalog.', [
                'metadata_key' => $metadataKey,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fetch metafield data (commission_override, affiliate_discount_pct) for the brand's products
     * via the Admin API. Returns a map keyed by product GID.
     *
     * @return array<string, array{commission_override: float|null, affiliate_discount_pct: float|null}>
     */
    private function fetchBrandMetafieldMap(string $brandProfessionalId): array
    {
        try {
            $brand = Professional::find($brandProfessionalId);

            if (! $brand) {
                return [];
            }

            $products = $this->brandCatalogService->fetchBrandCatalog($brand);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch brand metafields for affiliate catalog.', [
                'brand_professional_id' => $brandProfessionalId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $map = [];
        foreach ($products as $product) {
            $gid = $product['gid'] ?? '';
            if ($gid === '') {
                continue;
            }

            $metafields = $product['metafields'] ?? [];
            $map[$gid] = [
                'commission_override' => isset($metafields['commission_override']) ? (float) $metafields['commission_override'] : null,
                'affiliate_discount_pct' => isset($metafields['affiliate_discount_pct']) ? (float) $metafields['affiliate_discount_pct'] : null,
                'custom_photos_enabled' => $metafields['custom_photos_enabled'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * Query the Shopify Admin API to fetch all products from the active collection.
     *
     * If the active collection doesn't exist on Shopify (e.g. setup pipeline failed),
     * falls back to querying all products via the products() root field so the
     * affiliate still sees a catalog instead of an empty state.
     *
     * Uses Admin API (access_token) rather than Storefront API because custom-app
     * storefront tokens are scoped to the Online Store publication only — they
     * can't read products published exclusively to Hydrogen sales channels,
     * which is where every Partna brand publishes their catalog.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryAdminCatalog(string $brandProfessionalId): array
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return [];
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) ($integration->access_token ?? ''));
        // Arr::get's third argument is only used when the key is missing — when the
        // key exists with a null value (e.g. failed collection setup), it returns
        // null. ?: coerces null/empty to the default so we never send handle: null
        // to Shopify's String! GraphQL parameter, which would cause a validation
        // error that breaks the loop before the all-products fallback engages.
        $collectionHandle = Arr::get($metadata, 'active_collection_handle') ?: 'sidest-active-products';
        $apiVersion = config('services.shopify.api_version', '2025-01');

        if ($shopDomain === '' || $accessToken === '') {
            return [];
        }

        $url = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";
        $products = [];
        $cursor = null;
        $fallback = false;  // true once we switch to ALL_PRODUCTS_QUERY
        // Seed truthy so the fallback `continue` path (which skips the pageInfo
        // assignment) doesn't terminate the loop prematurely on the first
        // iteration before ALL_PRODUCTS_QUERY ever runs.
        $hasNextPage = true;

        do {
            if ($fallback) {
                $query = self::ALL_PRODUCTS_QUERY;
                $variables = ['first' => self::ADMIN_PRODUCTS_PER_PAGE];
                if ($cursor !== null) {
                    $variables['after'] = $cursor;
                }
            } else {
                $query = self::COLLECTION_PRODUCTS_QUERY;
                $variables = [
                    'handle' => $collectionHandle,
                    'first' => self::ADMIN_PRODUCTS_PER_PAGE,
                ];
                if ($cursor !== null) {
                    $variables['after'] = $cursor;
                }
            }

            try {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->withHeaders([
                        'X-Shopify-Access-Token' => $accessToken,
                    ])
                    ->post($url, [
                        'query' => $query,
                        'variables' => $variables,
                    ]);

                if (! $response->successful()) {
                    Log::warning('Shopify Admin API request failed.', [
                        'brand_professional_id' => $brandProfessionalId,
                        'status' => $response->status(),
                    ]);
                    break;
                }

                $data = $response->json();
                $errors = Arr::get($data, 'errors', []);

                if (! empty($errors)) {
                    Log::warning('Shopify Admin API returned errors.', [
                        'brand_professional_id' => $brandProfessionalId,
                        'errors' => $errors,
                    ]);
                    break;
                }

                // If the collection doesn't exist, data.collectionByHandle is null
                // and we get no edges. Switch to the all-products fallback for
                // this and subsequent pages.
                if (! $fallback && Arr::get($data, 'data.collectionByHandle') === null) {
                    Log::info('Shopify Admin collection not found, falling back to all products.', [
                        'brand_professional_id' => $brandProfessionalId,
                        'collection_handle' => $collectionHandle,
                    ]);
                    $fallback = true;
                    // Retry this page with the all-products query — reset the
                    // cursor so we start from the beginning of all products.
                    $cursor = null;

                    continue;
                }

                $edgesPath = $fallback ? 'data.products.edges' : 'data.collectionByHandle.products.edges';
                $pageInfoPath = $fallback ? 'data.products.pageInfo' : 'data.collectionByHandle.products.pageInfo';

                $edges = Arr::get($data, $edgesPath, []);

                if (! is_array($edges)) {
                    break;
                }

                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    $cursor = $edge['cursor'] ?? null;

                    // Admin API's variant.price is a Money scalar (string), while
                    // our downstream shape (and the frontend) expect a {amount,
                    // currencyCode} object. Borrow currency from the product's
                    // priceRange so the object shape stays intact — mirrors
                    // BrandCatalogService's Admin-API parsing.
                    $productCurrency = (string) Arr::get($node, 'priceRange.minVariantPrice.currencyCode', 'AUD');

                    $variants = [];
                    $anyVariantAvailable = false;
                    foreach (Arr::get($node, 'variants.edges', []) as $variantEdge) {
                        $v = $variantEdge['node'] ?? [];
                        $enabledVal = Arr::get($v, 'metafield.value');
                        $available = (bool) ($v['availableForSale'] ?? false);
                        $anyVariantAvailable = $anyVariantAvailable || $available;
                        $priceAmount = $v['price'] ?? null;
                        $variants[] = [
                            'gid' => $v['id'] ?? '',
                            'title' => $v['title'] ?? '',
                            'available_for_sale' => $available,
                            'price' => $priceAmount !== null
                                ? ['amount' => (string) $priceAmount, 'currencyCode' => $productCurrency]
                                : null,
                            'enabled' => $enabledVal !== null ? filter_var($enabledVal, FILTER_VALIDATE_BOOLEAN) : null,
                        ];
                    }

                    // Flatten the images connection into a plain array so the
                    // resource + frontend see the same shape both sides of
                    // the catalog (storefront vs admin API) return.
                    $images = array_values(array_filter(array_map(
                        fn ($imgEdge) => $imgEdge['node'] ?? null,
                        Arr::get($node, 'images.edges', [])
                    )));

                    // Admin API doesn't expose Product.availableForSale (that's a
                    // Storefront-API-only field), so derive it: ACTIVE status AND
                    // at least one variant is purchasable. Matches the semantic
                    // the affiliate UI uses to grey out unavailable cards.
                    $isActive = strtoupper((string) ($node['status'] ?? '')) === 'ACTIVE';
                    $productAvailable = $isActive && $anyVariantAvailable;

                    $products[] = [
                        'gid' => $node['id'] ?? '',
                        'title' => $node['title'] ?? '',
                        'handle' => $node['handle'] ?? '',
                        'description' => $node['description'] ?? '',
                        'available_for_sale' => $productAvailable,
                        'featured_image' => $node['featuredImage'] ?? null,
                        'images' => $images,
                        'price_range' => [
                            'min' => Arr::get($node, 'priceRange.minVariantPrice'),
                            'max' => Arr::get($node, 'priceRange.maxVariantPrice'),
                        ],
                        'variants' => $variants,
                    ];
                }

                $hasNextPage = Arr::get($data, $pageInfoPath.'.hasNextPage', false);
            } catch (\Throwable $e) {
                Log::error('Shopify Admin API exception.', [
                    'brand_professional_id' => $brandProfessionalId,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while ($hasNextPage && $cursor !== null);

        return $products;
    }
}
