<?php

namespace App\Services\Store;

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AffiliateProductCatalogService
{
    private const CATALOG_CACHE_TTL_MINUTES = 15;

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
        $cacheKey = CacheKeyGenerator::brandActiveCatalog($brandProfessionalId);

        return Cache::remember($cacheKey, now()->addMinutes(self::CATALOG_CACHE_TTL_MINUTES), function () use ($brandProfessionalId) {
            return $this->queryStorefrontCatalog($brandProfessionalId);
        });
    }

    /**
     * Return the brand's catalog with the affiliate's selection state merged in.
     *
     * @return array{products: array, brand_professional_id: string}
     */
    public function getCatalogWithSelections(Professional $affiliate): array
    {
        $resolved = $this->resolveAffiliateBrandIntegration($affiliate);
        $brandId = $resolved['brand_professional_id'];

        $catalog = $this->fetchActiveCatalog($brandId);

        $selections = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->get()
            ->keyBy('shopify_product_gid');

        $products = array_map(function (array $product) use ($selections) {
            $gid = $product['gid'] ?? '';
            $selection = $selections->get($gid);

            $product['selected'] = $selection !== null;
            $product['sort_order'] = $selection?->sort_order;

            return $product;
        }, $catalog);

        return [
            'products' => $products,
            'brand_professional_id' => $brandId,
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
                        $variants[] = [
                            'gid' => $v['id'] ?? '',
                            'title' => $v['title'] ?? '',
                            'available_for_sale' => $v['availableForSale'] ?? false,
                            'price' => $v['price'] ?? null,
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
