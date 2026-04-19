<?php

namespace App\Services\Store;

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AffiliateProductCatalogService
{
    public function __construct(
        private readonly BrandCatalogService $brandCatalogService
    ) {}
    private const STOREFRONT_PRODUCTS_PER_PAGE = 50;

    private const COLLECTION_PRODUCTS_QUERY = <<<'GRAPHQL'
query collectionProducts($handle: String!, $first: Int!, $after: String) {
  collection(handle: $handle) {
    products(first: $first, after: $after) {
      edges {
        node {
          id
          title
          handle
          availableForSale
          featuredImage {
            url
            altText
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
                price {
                  amount
                  currencyCode
                }
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

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        if (trim((string) Arr::get($metadata, 'storefront_access_token', '')) === '') {
            throw new \RuntimeException('Storefront is not yet configured. Please try again shortly.', 503);
        }

        return [
            'brand_professional_id' => (string) $link->brand_professional_id,
            'integration' => $integration,
        ];
    }

    /**
     * Fetch the brand's active product catalog from Shopify Storefront API (cached).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchActiveCatalog(string $brandProfessionalId): array
    {
        return $this->queryStorefrontCatalog($brandProfessionalId);
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
     * @return array{products: array, brand_professional_id: string, default_commission_rate: float}
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
            : (float) config('sidest.store.default_commission_rate', 15);

        $selections = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->get()
            ->keyBy('shopify_product_gid');

        $products = array_map(function (array $product) use ($selections, $metafieldMap, $favouritesGids) {
            $gid = $product['gid'] ?? '';
            $selection = $selections->get($gid);

            $product['selected'] = $selection !== null;
            $product['sort_order'] = $selection?->sort_order;

            // Merge metafield data from Admin API
            $meta = $metafieldMap[$gid] ?? [];
            $product['commission_override'] = $meta['commission_override'] ?? null;
            $product['affiliate_discount_pct'] = $meta['affiliate_discount_pct'] ?? null;

            // Server-side variant filter: variants with sidest.enabled=false are hidden.
            // Missing metafield (null) or true = variant is available (dynamic default).
            // Hydrogen reads this directly from the Storefront API; this filter ensures
            // the affiliate dashboard also respects the restriction.
            $product['variants'] = array_values(array_filter(
                $product['variants'] ?? [],
                fn (array $variant) => ($variant['enabled'] ?? null) !== false
            ));

            // Collection membership flags
            $product['in_favourites'] = in_array($gid, $favouritesGids, true);

            return $product;
        }, $catalog);

        return [
            'products' => $products,
            'brand_professional_id' => $brandId,
            'default_commission_rate' => $defaultCommissionRate,
        ];
    }

    /**
     * Return selections whose GIDs no longer appear in the brand's active catalog.
     */
    public function getStaleSelections(Professional $affiliate): Collection
    {
        $resolved = $this->resolveAffiliateBrandIntegration($affiliate);
        $catalog = $this->fetchActiveCatalog($resolved['brand_professional_id']);

        $activeGids = collect($catalog)->pluck('gid')->all();

        return AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->get()
            ->filter(fn (AffiliateProductSelection $sel) => ! in_array($sel->shopify_product_gid, $activeGids, true));
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
     * Fetch product GIDs from a brand collection (e.g. favourites) using the Admin API.
     *
     * @param  string  $metadataKey  The key in provider_metadata for the collection handle
     * @return array<int, string>  List of product GIDs in the collection
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
            ];
        }

        return $map;
    }

    /**
     * Query the Shopify Storefront API to fetch all products from the active collection.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queryStorefrontCatalog(string $brandProfessionalId): array
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
        $storefrontToken = trim((string) Arr::get($metadata, 'storefront_access_token', ''));
        $collectionHandle = Arr::get($metadata, 'active_collection_handle', 'sidest-active-products');
        $apiVersion = config('services.shopify.api_version', '2025-01');

        if ($shopDomain === '' || $storefrontToken === '') {
            return [];
        }

        $url = "https://{$shopDomain}/api/{$apiVersion}/graphql.json";
        $products = [];
        $cursor = null;

        do {
            $variables = [
                'handle' => $collectionHandle,
                'first' => self::STOREFRONT_PRODUCTS_PER_PAGE,
            ];

            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            try {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->withHeaders([
                        'X-Shopify-Storefront-Access-Token' => $storefrontToken,
                    ])
                    ->post($url, [
                        'query' => self::COLLECTION_PRODUCTS_QUERY,
                        'variables' => $variables,
                    ]);

                if (! $response->successful()) {
                    Log::warning('Storefront API request failed.', [
                        'brand_professional_id' => $brandProfessionalId,
                        'status' => $response->status(),
                    ]);
                    break;
                }

                $data = $response->json();
                $errors = Arr::get($data, 'errors', []);

                if (! empty($errors)) {
                    Log::warning('Storefront API returned errors.', [
                        'brand_professional_id' => $brandProfessionalId,
                        'errors' => $errors,
                    ]);
                    break;
                }

                $edges = Arr::get($data, 'data.collection.products.edges', []);

                if (! is_array($edges)) {
                    break;
                }

                foreach ($edges as $edge) {
                    $node = $edge['node'] ?? [];
                    $cursor = $edge['cursor'] ?? null;

                    $variants = [];
                    foreach (Arr::get($node, 'variants.edges', []) as $variantEdge) {
                        $v = $variantEdge['node'] ?? [];
                        $enabledVal = Arr::get($v, 'metafield.value');
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
                        'available_for_sale' => $node['availableForSale'] ?? false,
                        'featured_image' => $node['featuredImage'] ?? null,
                        'price_range' => [
                            'min' => Arr::get($node, 'priceRange.minVariantPrice'),
                            'max' => Arr::get($node, 'priceRange.maxVariantPrice'),
                        ],
                        'variants' => $variants,
                    ];
                }

                $hasNextPage = Arr::get($data, 'data.collection.products.pageInfo.hasNextPage', false);
            } catch (\Throwable $e) {
                Log::error('Storefront API exception.', [
                    'brand_professional_id' => $brandProfessionalId,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while ($hasNextPage && $cursor !== null);

        return $products;
    }
}
