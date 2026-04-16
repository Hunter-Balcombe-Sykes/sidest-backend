<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V2: Creates sidest.* metafield definitions on the brand's Shopify store. Idempotent — skips existing definitions.
class CreateShopifyMetafieldsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    private const METAFIELD_DEFINITION_CREATE = <<<'GRAPHQL'
    mutation metafieldDefinitionCreate($definition: MetafieldDefinitionInput!) {
      metafieldDefinitionCreate(definition: $definition) {
        createdDefinition {
          id
          namespace
          key
          useAsCollectionCondition
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    private const METAFIELD_DEFINITION_DELETE = <<<'GRAPHQL'
    mutation metafieldDefinitionDelete($id: ID!, $deleteAllAssociatedMetafields: Boolean!) {
      metafieldDefinitionDelete(id: $id, deleteAllAssociatedMetafields: $deleteAllAssociatedMetafields) {
        deletedDefinitionId
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    private const METAFIELD_DEFINITIONS_QUERY = <<<'GRAPHQL'
    query metafieldDefinitions($ownerType: MetafieldOwnerType!, $namespace: String!, $first: Int!) {
      metafieldDefinitions(ownerType: $ownerType, namespace: $namespace, first: $first) {
        edges {
          node {
            id
            namespace
            key
            useAsCollectionCondition
          }
        }
      }
    }
    GRAPHQL;

    // Keys that must have useAsCollectionCondition enabled for smart collection rules
    private const COLLECTION_CONDITION_KEYS = ['active', 'commission_override'];

    private const PRODUCT_DEFINITIONS = [
        [
            'key' => 'active',
            'name' => 'Side St Active',
            'type' => 'boolean',
            'description' => 'Whether this product is active for Side St affiliates',
            'access' => ['storefront' => 'PUBLIC_READ'],
        ],
        [
            'key' => 'commission_override',
            'name' => 'Side St Commission Override',
            'type' => 'number_decimal',
            'description' => 'Per-product commission % override (null = use brand default)',
            'access' => ['storefront' => 'PUBLIC_READ'],
        ],
        [
            'key' => 'affiliate_discount_pct',
            'name' => 'Side St Affiliate Discount',
            'type' => 'number_decimal',
            'description' => 'Discount % applied at checkout for affiliate customers',
            'access' => [],
        ],
    ];

    // Variant-level definitions — ownerType: PRODUCTVARIANT
    // sidest.enabled controls per-variant visibility for affiliates and Hydrogen.
    // Missing metafield = enabled (dynamic default); only "false" hides a variant.
    // PUBLIC_READ so Hydrogen reads directly from the Storefront API.
    private const PRODUCT_VARIANT_DEFINITIONS = [
        [
            'key' => 'enabled',
            'name' => 'Side St Variant Enabled',
            'type' => 'boolean',
            'description' => 'Whether this variant is available for Side St affiliates (missing = enabled)',
            'access' => ['storefront' => 'PUBLIC_READ'],
        ],
    ];

    private const SHOP_DEFINITIONS = [
        ['key' => 'default_commission_rate', 'name' => 'Side St Default Commission Rate', 'type' => 'number_decimal', 'description' => 'Brand-wide default commission %'],
        ['key' => 'accent_color', 'name' => 'Side St Accent Color', 'type' => 'single_line_text_field', 'description' => 'Hex colour for affiliate storefronts'],
        ['key' => 'theme_variant', 'name' => 'Side St Theme Variant', 'type' => 'single_line_text_field', 'description' => 'Theme key (e.g. 1 through 5)'],
        ['key' => 'product_image_ratio', 'name' => 'Side St Product Image Ratio', 'type' => 'single_line_text_field', 'description' => 'Product image ratio (1/1 or 4/5)'],
        ['key' => 'active_collection_handle', 'name' => 'Side St Active Collection Handle', 'type' => 'single_line_text_field', 'description' => 'Handle of Active Products smart collection'],
        ['key' => 'default_collection_handle', 'name' => 'Side St Default Collection Handle', 'type' => 'single_line_text_field', 'description' => 'Handle of Default Products manual collection'],
        ['key' => 'favourites_collection_handle', 'name' => 'Side St Favourites Collection Handle', 'type' => 'single_line_text_field', 'description' => 'Handle of Brand Favourites manual collection'],
        ['key' => 'high_commission_collection_handle', 'name' => 'Side St High Commission Collection Handle', 'type' => 'single_line_text_field', 'description' => 'Handle of High Commission Products smart collection'],
        ['key' => 'setup_complete', 'name' => 'Side St Setup Complete', 'type' => 'boolean', 'description' => 'Whether brand has completed the setup wizard'],
        ['key' => 'theme_tokens', 'name' => 'Side St Theme Tokens', 'type' => 'json', 'description' => 'Extracted CSS design tokens from the brand storefront theme'],
    ];

    public function __construct(
        public string $integrationId
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('id', $this->integrationId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return;
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        if ($shopDomain === '' || $accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            $integration->mergeProviderMetadata(['metafield_definitions_state' => 'failed']);

            return;
        }

        try {
            $existingProduct = $this->getExistingDefinitions($shopDomain, $accessToken, $apiVersion, 'PRODUCT');
            $existingShop = $this->getExistingDefinitions($shopDomain, $accessToken, $apiVersion, 'SHOP');
            $existingProductKeys = array_column($existingProduct, 'key');
            $existingShopKeys = array_column($existingShop, 'key');

            // Create product-level definitions
            foreach (self::PRODUCT_DEFINITIONS as $def) {
                $needsCollectionCondition = in_array($def['key'], self::COLLECTION_CONDITION_KEYS, true);

                if (in_array($def['key'], $existingProductKeys, true)) {
                    // If it needs useAsCollectionCondition but doesn't have it, delete and recreate
                    if ($needsCollectionCondition) {
                        $existing = Arr::first($existingProduct, fn ($d) => $d['key'] === $def['key']);
                        if ($existing && ! $existing['useAsCollectionCondition']) {
                            Log::info('Deleting metafield definition to recreate with useAsCollectionCondition', [
                                'key' => $def['key'],
                                'id' => $existing['id'],
                            ]);
                            $this->deleteDefinition($shopDomain, $accessToken, $apiVersion, $existing['id']);
                            // Note: delete + create is non-atomic. If create fails after delete,
                            // the next retry will find the definition missing and recreate it cleanly.
                            // Fall through to create below
                        } else {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                $this->createDefinition($shopDomain, $accessToken, $apiVersion, 'PRODUCT', $def, $needsCollectionCondition);
            }

            // Create variant-level definitions
            $existingVariant = $this->getExistingDefinitions($shopDomain, $accessToken, $apiVersion, 'PRODUCTVARIANT');
            $existingVariantKeys = array_column($existingVariant, 'key');

            foreach (self::PRODUCT_VARIANT_DEFINITIONS as $def) {
                if (in_array($def['key'], $existingVariantKeys, true)) {
                    continue;
                }
                $this->createDefinition($shopDomain, $accessToken, $apiVersion, 'PRODUCTVARIANT', $def);
            }

            // Create shop-level definitions
            foreach (self::SHOP_DEFINITIONS as $def) {
                if (in_array($def['key'], $existingShopKeys, true)) {
                    continue;
                }
                $this->createDefinition($shopDomain, $accessToken, $apiVersion, 'SHOP', $def);
            }

            $integration->mergeProviderMetadata(['metafield_definitions_state' => 'registered']);

            Log::info('Shopify metafield definitions created', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
            ]);

            // Collections depend on metafield definitions existing — dispatch after metafields are created
            CreateShopifyCollectionsJob::dispatch($this->integrationId);
        } catch (\Throwable $e) {
            Log::error('Failed to create Shopify metafield definitions', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $integration = ProfessionalIntegration::find($this->integrationId);
        $integration?->mergeProviderMetadata(['metafield_definitions_state' => 'failed']);
    }

    /**
     * @return array<int, array{id: string, key: string, useAsCollectionCondition: bool}>
     */
    private function getExistingDefinitions(string $shopDomain, string $accessToken, string $apiVersion, string $ownerType): array
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELD_DEFINITIONS_QUERY, [
            'ownerType' => $ownerType,
            'namespace' => 'sidest',
            'first' => 50,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $edges = $response->json('data.metafieldDefinitions.edges', []);

        return array_map(
            static fn (array $edge): array => [
                'id' => (string) Arr::get($edge, 'node.id', ''),
                'key' => (string) Arr::get($edge, 'node.key', ''),
                'useAsCollectionCondition' => (bool) Arr::get($edge, 'node.useAsCollectionCondition', false),
            ],
            is_array($edges) ? $edges : []
        );
    }

    private function createDefinition(string $shopDomain, string $accessToken, string $apiVersion, string $ownerType, array $def, bool $useAsCollectionCondition = false): void
    {
        $definition = [
            'namespace' => 'sidest',
            'key' => $def['key'],
            'name' => $def['name'],
            'type' => $def['type'],
            'description' => $def['description'],
            'ownerType' => $ownerType,
        ];

        if (! empty($def['access'])) {
            $definition['access'] = $def['access'];
        }

        if ($useAsCollectionCondition) {
            $definition['useAsCollectionCondition'] = true;
        }

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELD_DEFINITION_CREATE, [
            'definition' => $definition,
        ]);

        $userErrors = $response->json('data.metafieldDefinitionCreate.userErrors', []);
        if (! empty($userErrors)) {
            Log::warning('Shopify metafield definition creation had errors', [
                'key' => $def['key'],
                'owner_type' => $ownerType,
                'use_as_collection_condition' => $useAsCollectionCondition,
                'errors' => $userErrors,
            ]);
        } else {
            $created = $response->json('data.metafieldDefinitionCreate.createdDefinition');
            Log::info('Shopify metafield definition created', [
                'key' => $def['key'],
                'owner_type' => $ownerType,
                'id' => $created['id'] ?? null,
                'useAsCollectionCondition' => $created['useAsCollectionCondition'] ?? null,
            ]);
        }
    }

    private function deleteDefinition(string $shopDomain, string $accessToken, string $apiVersion, string $definitionId): void
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELD_DEFINITION_DELETE, [
            'id' => $definitionId,
            'deleteAllAssociatedMetafields' => false,
        ]);

        $userErrors = $response->json('data.metafieldDefinitionDelete.userErrors', []);
        if (! empty($userErrors)) {
            Log::warning('Failed to delete metafield definition', [
                'definition_id' => $definitionId,
                'errors' => $userErrors,
            ]);
        }
    }

    private function graphql(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post(
            "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
            array_filter([
                'query' => $query,
                'variables' => ! empty($variables) ? $variables : null,
            ])
        );
    }
}
