<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
    /**
     * GET — Return product metafields, collection membership, variant list,
     * and global settings the extension needs on mount.
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
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
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

        // Read metafields written by the brand catalog service
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $productId = $this->extractId($productGid);

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

        return $this->success([
            'active' => $metafields['active'] ?? true,
            'commission_override' => $metafields['commission_override'] ?? null,
            'affiliate_discount_pct' => $metafields['affiliate_discount_pct'] ?? null,
            'custom_photos_enabled' => $metafields['custom_photos_enabled'] ?? null,
            'default_commission_rate' => $defaultCommissionRate,
            'global_custom_photos_enabled' => $globalCustomPhotosEnabled,
            'in_favourites_collection' => $inFavourites,
            'in_default_collection' => $inDefault,
            'variants' => $variants,
        ]);
    }

    /**
     * PATCH — Save a single field change. The extension saves per-field
     * on change (no monolithic Save button).
     *
     * Body: { product_gid: string, field: string, value: mixed }
     *
     * Supported fields:
     *   - active (bool)
     *   - commission_override (float|null)
     *   - affiliate_discount_pct (float|null)
     *   - custom_photos_enabled (bool|null)
     *   - add_to_favourites (bool)
     *   - add_to_default (bool)
     *   - disabled_variant_gids (string[])
     */
    public function update(Request $request): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $productGid = (string) ($request->input('product_gid') ?? '');
        $field = (string) ($request->input('field') ?? '');
        $value = $request->input('value');

        if ($productGid === '' || $field === '') {
            return $this->error('product_gid and field are required.', 422);
        }

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('No Shopify integration found.', 404);
        }

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

                default => throw new \InvalidArgumentException("Unknown field: {$field}"),
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

        return $this->success([], 'Saved.');
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function extractId(string $gid): string
    {
        return (string) preg_replace('#^gid://shopify/Product/#', '', $gid);
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
        $apiVersion = config('services.shopify.api_version', '2025-01');

        $empty = ['metafields' => [], 'variants' => []];

        if ($shopDomain === '' || $adminToken === '') {
            return $empty;
        }

        $query = <<<'GRAPHQL'
query productMetafields($id: ID!) {
  product(id: $id) {
    active: metafield(namespace: "sidest", key: "active") { value }
    commissionOverride: metafield(namespace: "sidest", key: "commission_override") { value }
    affiliateDiscountPct: metafield(namespace: "sidest", key: "affiliate_discount_pct") { value }
    customPhotosEnabled: metafield(namespace: "sidest", key: "custom_photos_enabled") { value }
    variants(first: 50) {
      edges {
        node {
          id
          title
          enabled: metafield(namespace: "sidest", key: "enabled") { value }
        }
      }
    }
  }
}
GRAPHQL;

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
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
     * @return array<int, array{gid: string, title: string, enabled: bool}>
     */
    private function fetchVariants(ProfessionalIntegration $integration, string $productId): array
    {
        $shopDomain = trim((string) Arr::get($integration->provider_metadata ?? [], 'shop_domain', ''));
        $adminToken = trim((string) ($integration->access_token ?? ''));
        $apiVersion = config('services.shopify.api_version', '2025-01');

        if ($shopDomain === '' || $adminToken === '') {
            return [];
        }

        $query = <<<'GRAPHQL'
query productVariants($id: ID!) {
  product(id: $id) {
    variants(first: 50) {
      edges {
        node {
          id
          title
          enabled: metafield(namespace: "sidest", key: "enabled") { value }
        }
      }
    }
  }
}
GRAPHQL;

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
                ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                    'query' => $query,
                    'variables' => ['id' => "gid://shopify/Product/{$productId}"],
                ]);

            $data = $response->json();
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
            return [];
        }
    }

    /**
     * Check if a product GID is a member of a Shopify collection.
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
        $apiVersion = config('services.shopify.api_version', '2025-01');

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
            $response = \Illuminate\Support\Facades\Http::timeout(10)
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
        } catch (\Throwable) {
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

        $ownerType = $field === 'disabled_variant_gids' ? 'PRODUCTVARIANT' : 'PRODUCT';

        // For disabled_variant_gids, we need to update individual variant metafields.
        // The value is an array of variant GIDs to disable; all others are enabled.
        if ($field === 'disabled_variant_gids') {
            $this->saveVariantEnabledStates($integration, $productGid, is_array($value) ? $value : []);

            return;
        }

        $shopDomain = trim((string) Arr::get($integration->provider_metadata ?? [], 'shop_domain', ''));
        $adminToken = trim((string) ($integration->access_token ?? ''));
        $apiVersion = config('services.shopify.api_version', '2025-01');

        if ($shopDomain === '' || $adminToken === '') {
            throw new \RuntimeException('Shopify integration is missing credentials.');
        }

        $ownerId = $productGid;

        $metafieldQuery = <<<'GRAPHQL'
query findMetafield($ownerId: ID!, $namespace: String!, $key: String!) {
  product(id: $ownerId) {
    metafield(namespace: $namespace, key: $key) {
      id
      value
    }
  }
}
GRAPHQL;

        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $metafieldQuery,
                'variables' => [
                    'ownerId' => $ownerId,
                    'namespace' => 'sidest',
                    'key' => $key,
                ],
            ]);

        $data = $response->json();
        $metafieldId = Arr::get($data, 'data.product.metafield.id');

        // Build the value for the metafield
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

        if ($metafieldId) {
            // Update existing metafield
            $mutation = <<<'GRAPHQL'
mutation updateMetafield($input: MetafieldInput!) {
  metafieldUpdate(input: $input) {
    metafield { id }
    userErrors { field message }
  }
}
GRAPHQL;
            $variables = ['input' => [
                'id' => $metafieldId,
                'value' => $typedValue,
                'type' => $type,
            ]];
        } else {
            // Create new metafield
            $mutation = <<<'GRAPHQL'
mutation createMetafield($metafield: MetafieldInput!) {
  metafieldDefinitionCreate(metafield: $metafield) {
    metafieldDefinition { id }
    userErrors { field message }
  }
}
GRAPHQL;
            // For setting a metafield value, use the productSet mutation
            $mutation = <<<'GRAPHQL'
mutation setProductMetafield($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id }
    userErrors { field message }
  }
}
GRAPHQL;
            $variables = ['input' => [
                'id' => $ownerId,
                'metafields' => [[
                    'namespace' => 'sidest',
                    'key' => $key,
                    'value' => $typedValue,
                    'type' => $type,
                ]],
            ]];
        }

        $result = \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $mutation,
                'variables' => $variables,
            ]);

        $resultData = $result->json();
        if (! $result->successful()) {
            throw new \RuntimeException("Shopify API returned {$result->status()}");
        }

        $errors = Arr::get($resultData, 'data.productUpdate.userErrors', Arr::get($resultData, 'data.metafieldUpdate.userErrors', []));
        if (! empty($errors)) {
            $msg = is_array($errors) ? ($errors[0]['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException($msg);
        }
    }

    /**
     * Update variant enabled states. Disabled variant GIDs get sidest.enabled=false;
     * all other variants of the same product get sidest.enabled=true.
     */
    private function saveVariantEnabledStates(ProfessionalIntegration $integration, string $productGid, array $disabledGids): void
    {
        $variants = $this->fetchVariants($integration, $this->extractId($productGid));

        foreach ($variants as $variant) {
            $shouldEnable = ! in_array($variant['gid'], $disabledGids, true);
            if ($variant['enabled'] !== $shouldEnable) {
                $this->saveVariantMetafield($integration, $variant['gid'], $shouldEnable);
            }
        }
    }

    /**
     * Set sidest.enabled on a single variant.
     */
    private function saveVariantMetafield(ProfessionalIntegration $integration, string $variantGid, bool $enabled): void
    {
        $shopDomain = trim((string) Arr::get($integration->provider_metadata ?? [], 'shop_domain', ''));
        $adminToken = trim((string) ($integration->access_token ?? ''));
        $apiVersion = config('services.shopify.api_version', '2025-01');

        if ($shopDomain === '' || $adminToken === '') {
            return;
        }

        $values = $enabled ? 'true' : 'false';

        $mutation = <<<'GRAPHQL'
mutation setVariantMetafield($input: ProductVariantInput!) {
  productVariantUpdate(input: $input) {
    productVariant { id }
    userErrors { field message }
  }
}
GRAPHQL;

        \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $mutation,
                'variables' => ['input' => [
                    'id' => $variantGid,
                    'metafields' => [[
                        'namespace' => 'sidest',
                        'key' => 'enabled',
                        'value' => $values,
                        'type' => 'boolean',
                    ]],
                ]],
            ]);
    }

    /**
     * Add or remove a product from a manual Shopify collection.
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
        $apiVersion = config('services.shopify.api_version', '2025-01');

        if ($shopDomain === '' || $adminToken === '') {
            throw new \RuntimeException('Shopify integration is missing credentials.');
        }

        // Resolve collection ID from handle
        $query = <<<'GRAPHQL'
query collectionId($handle: String!) {
  collection(handle: $handle) { id }
}
GRAPHQL;

        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $query,
                'variables' => ['handle' => $collectionHandle],
            ]);

        $data = $response->json();
        $collectionId = Arr::get($data, 'data.collection.id');

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

        $result = \Illuminate\Support\Facades\Http::timeout(15)
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
