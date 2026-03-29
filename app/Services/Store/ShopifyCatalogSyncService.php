<?php

namespace App\Services\Store;

use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ShopifyCatalogSyncService
{
    use NormalizesShopDomain;

private const PRODUCTS_QUERY = <<<'GRAPHQL'
query ProductsPage($first: Int!, $after: String, $query: String) {
  products(first: $first, after: $after, query: $query) {
    pageInfo {
      hasNextPage
      endCursor
    }
    edges {
      node {
        id
        title
        handle
        status
        onlineStoreUrl
        updatedAt
        description
        productType
        tags
        featuredImage {
          url
        }
        images(first: 10) {
          edges {
            node {
              url
              altText
            }
          }
        }
        priceRangeV2 {
          minVariantPrice {
            amount
            currencyCode
          }
        }
      }
    }
  }
}
GRAPHQL;

    public function __construct(
        private readonly BrandProductSettingsService $settingsRows,
    ) {}

    /**
     * @return array{
     *   synced:int,
     *   marked_deleted:int,
     *   inserted_settings_rows:int,
     *   skipped:bool,
     *   reason?:string
     * }
     */
    public function syncForBrand(string $brandProfessionalId): array
    {
        $brandProfessionalId = trim($brandProfessionalId);
        if ($brandProfessionalId === '') {
            return [
                'synced' => 0,
                'marked_deleted' => 0,
                'inserted_settings_rows' => 0,
                'skipped' => true,
                'reason' => 'missing_brand_professional_id',
            ];
        }

        $integration = ProfessionalIntegration::query()
            ->provider(ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('professional_id', $brandProfessionalId)
            ->first();

        if (! $integration || empty($integration->access_token)) {
            return [
                'synced' => 0,
                'marked_deleted' => 0,
                'inserted_settings_rows' => 0,
                'skipped' => true,
                'reason' => 'shopify_not_connected',
            ];
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = $this->normalizeShopDomain((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) ($integration->access_token ?? ''));

        if ($shopDomain === '' || $accessToken === '') {
            return [
                'synced' => 0,
                'marked_deleted' => 0,
                'inserted_settings_rows' => 0,
                'skipped' => true,
                'reason' => 'missing_shop_domain_or_access_token',
            ];
        }

        $products = $this->fetchAllProducts($shopDomain, $accessToken);
        $now = now();
        $enterpriseId = Professional::query()
            ->where('id', $brandProfessionalId)
            ->value('primary_enterprise_id');

        $upsertRows = [];
        $syncedShopifyIds = [];

        foreach ($products as $product) {
            $shopifyProductId = trim((string) Arr::get($product, 'id', ''));
            if ($shopifyProductId === '') {
                continue;
            }

            $title = trim((string) Arr::get($product, 'title', ''));
            if ($title === '') {
                $digits = $this->extractNumericShopifyProductId($shopifyProductId) ?? 'unknown';
                $title = "Shopify Product {$digits}";
            }

            $amountRaw = Arr::get($product, 'priceRangeV2.minVariantPrice.amount');
            $priceCents = is_numeric($amountRaw) ? (int) round(((float) $amountRaw) * 100) : null;

            $currencyCode = strtoupper(trim((string) (
                Arr::get($product, 'priceRangeV2.minVariantPrice.currencyCode', 'AUD')
            )));
            if ($currencyCode === '') {
                $currencyCode = 'AUD';
            }

            $status = $this->mapShopifyStatus((string) Arr::get($product, 'status', 'unknown'));
            if ($status === 'draft') {
                continue;
            }
            $handle = trim((string) Arr::get($product, 'handle', ''));
            $productUrl = trim((string) Arr::get($product, 'onlineStoreUrl', ''));
            $imageUrl = trim((string) Arr::get($product, 'featuredImage.url', ''));
            $shopifyUpdatedAt = trim((string) Arr::get($product, 'updatedAt', ''));
            $description = trim((string) Arr::get($product, 'description', ''));
            $productType = trim((string) Arr::get($product, 'productType', ''));
            $tags = array_values(array_filter(
                array_map('trim', (array) Arr::get($product, 'tags', [])),
                static fn (string $t): bool => $t !== ''
            ));
            $imageEdges = (array) Arr::get($product, 'images.edges', []);
            $images = array_values(array_filter(array_map(static function ($edge): ?array {
                $node = is_array($edge) ? Arr::get($edge, 'node') : null;
                if (! is_array($node)) {
                    return null;
                }
                $url = trim((string) ($node['url'] ?? ''));
                return $url !== '' ? ['url' => $url, 'altText' => ($node['altText'] ?? null)] : null;
            }, $imageEdges)));

            $upsertRows[] = [
                'brand_professional_id' => $brandProfessionalId,
                'enterprise_id' => $enterpriseId,
                'shopify_product_id' => $shopifyProductId,
                'title' => $title,
                'handle' => $handle !== '' ? $handle : null,
                'product_url' => $productUrl !== '' ? $productUrl : null,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'description' => $description !== '' ? $description : null,
                'product_type' => $productType !== '' ? $productType : null,
                'tags' => '{' . implode(',', array_map(static fn (string $t): string => '"' . str_replace('"', '\\"', $t) . '"', $tags)) . '}',
                'images' => json_encode($images, JSON_UNESCAPED_SLASHES),
                'price_cents' => $priceCents,
                'currency_code' => $currencyCode,
                'shopify_status' => $status,
                'is_sync_active' => true,
                'last_synced_at' => $now,
                'metadata' => json_encode([
                    'source' => 'shopify_admin_graphql',
                    'shop_domain' => $shopDomain,
                    'shopify_updated_at' => $shopifyUpdatedAt !== '' ? $shopifyUpdatedAt : null,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $syncedShopifyIds[] = $shopifyProductId;
        }

        if ($upsertRows !== []) {
            DB::table('retail.brand_products')->upsert(
                $upsertRows,
                ['brand_professional_id', 'shopify_product_id'],
                [
                    'enterprise_id',
                    'title',
                    'handle',
                    'product_url',
                    'image_url',
                    'description',
                    'product_type',
                    'tags',
                    'images',
                    'price_cents',
                    'currency_code',
                    'shopify_status',
                    'is_sync_active',
                    'last_synced_at',
                    'metadata',
                    'updated_at',
                ]
            );
        }

        $markDeletedQuery = DB::table('retail.brand_products')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('is_sync_active', true);

        if ($syncedShopifyIds !== []) {
            $markDeletedQuery->whereNotIn('shopify_product_id', array_values(array_unique($syncedShopifyIds)));
        }

        $markedDeleted = $markDeletedQuery->update([
            'is_sync_active' => false,
            'shopify_status' => 'deleted',
            'last_synced_at' => $now,
            'updated_at' => $now,
        ]);

        $insertedSettingsRows = $this->settingsRows->ensureSettingsRowsForBrand($brandProfessionalId);

        return [
            'synced' => count($upsertRows),
            'marked_deleted' => (int) $markedDeleted,
            'inserted_settings_rows' => (int) $insertedSettingsRows,
            'skipped' => false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllProducts(string $shopDomain, string $accessToken): array
    {
        $products = [];
        $cursor = null;
        $hasNextPage = true;
        $apiVersion = trim((string) config('services.shopify.version', '2025-01'));

        while ($hasNextPage) {
            $data = $this->queryShopify(
                $shopDomain,
                $accessToken,
                $apiVersion,
                self::PRODUCTS_QUERY,
                [
                    'first' => 250,
                    'after' => $cursor,
                    'query' => '(status:active OR status:archived)',
                ]
            );

            $connection = Arr::get($data, 'products');
            $edges = Arr::get($connection, 'edges', []);
            foreach ($edges as $edge) {
                $node = Arr::get($edge, 'node');
                if (is_array($node)) {
                    $products[] = $node;
                }
            }

            $hasNextPage = (bool) Arr::get($connection, 'pageInfo.hasNextPage', false);
            $cursor = Arr::get($connection, 'pageInfo.endCursor');
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function queryShopify(
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        string $query,
        array $variables = []
    ): array {
        $endpoint = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($endpoint, [
                'query' => $query,
                'variables' => $variables,
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException("Shopify catalog sync failed (HTTP {$response->status()}).");
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];
        $errors = Arr::get($payload, 'errors', []);
        if (is_array($errors) && $errors !== []) {
            $message = (string) Arr::get($errors, '0.message', 'Shopify GraphQL returned errors.');
            throw new \RuntimeException($message);
        }

        /** @var array<string, mixed> $data */
        $data = Arr::get($payload, 'data', []);
        return is_array($data) ? $data : [];
    }

    private function mapShopifyStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'active' => 'active',
            'draft' => 'draft',
            'archived' => 'archived',
            'deleted' => 'deleted',
            default => 'unknown',
        };
    }

    private function extractNumericShopifyProductId(string $shopifyProductId): ?string
    {
        if (preg_match('/(\d+)(?!.*\d)/', $shopifyProductId, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
