<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
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
            access {
              storefront
            }
          }
        }
      }
    }
    GRAPHQL;

    // Keys that must have useAsCollectionCondition enabled for smart collection rules.
    // has_enabled_variants is included so the "Active Products" smart collection can
    // require BOTH sidest.active=true AND sidest.has_enabled_variants=true — a product
    // with every variant disabled must fall out of the active catalog automatically.
    private const COLLECTION_CONDITION_KEYS = ['active', 'commission_override', 'has_enabled_variants'];

    private const PRODUCT_DEFINITIONS = [
        [
            'key' => 'active',
            'name' => 'Partna Active',
            'type' => 'boolean',
            'description' => 'Whether this product is active for Partna affiliates',
            'access' => ['storefront' => 'PUBLIC_READ'],
        ],
        [
            'key' => 'commission_override',
            'name' => 'Partna Commission Override',
            'type' => 'number_decimal',
            'description' => 'Per-product commission % override (null = use brand default)',
            'access' => ['storefront' => 'PUBLIC_READ'],
        ],
        [
            'key' => 'affiliate_discount_pct',
            'name' => 'Partna Affiliate Discount',
            'type' => 'number_decimal',
            'description' => 'Discount % applied at checkout for affiliate customers (read by the Partna Price Shopify Function + Hydrogen display)',
            // PUBLIC_READ so Hydrogen fetches the value via Storefront API and
            // renders the discounted price directly — customers never see the
            // Shopify sticker price on an affiliate sitepage. Also read by the
            // sidest-affiliate-discount Shopify Function via the Admin-API-style
            // function input so the discount actually applies at checkout.
            'access' => ['storefront' => 'PUBLIC_READ'],
        ],
        // Derived from per-variant sidest.enabled state. Written by BrandCatalogService
        // whenever variant states change (or via the backfill command for existing
        // products). Used as a smart-collection condition so products with every
        // variant disabled automatically drop out of the Active Products collection
        // without requiring the brand to flip sidest.active themselves.
        [
            'key' => 'has_enabled_variants',
            'name' => 'Partna Has Enabled Variants',
            'type' => 'boolean',
            'description' => 'Derived: true if the product has at least one variant with sidest.enabled != false (or no variants at all). Smart collection condition.',
            'access' => ['storefront' => 'PUBLIC_READ'],
        ],
    ];

    // Variant-level definitions — ownerType: PRODUCTVARIANT
    // sidest.enabled controls per-variant visibility for affiliates and Hydrogen.
    // Missing metafield = enabled (dynamic default); only "false" hides a variant.
    // PUBLIC_READ so Hydrogen reads directly from the Storefront API.
    private const PRODUCT_VARIANT_DEFINITIONS = [
        [
            'key' => 'enabled',
            'name' => 'Partna Variant Enabled',
            'type' => 'boolean',
            'description' => 'Whether this variant is available for Partna affiliates (missing = enabled)',
            'access' => ['storefront' => 'PUBLIC_READ'],
        ],
    ];

    private const SHOP_DEFINITIONS = [
        ['key' => 'default_commission_rate', 'name' => 'Partna Default Commission Rate', 'type' => 'number_decimal', 'description' => 'Brand-wide default commission %'],
        ['key' => 'accent_color', 'name' => 'Partna Accent Color', 'type' => 'single_line_text_field', 'description' => 'Hex colour for affiliate storefronts'],
        ['key' => 'theme_variant', 'name' => 'Partna Theme Variant', 'type' => 'single_line_text_field', 'description' => 'Theme key (e.g. 1 through 5)'],
        ['key' => 'product_image_ratio', 'name' => 'Partna Product Image Ratio', 'type' => 'single_line_text_field', 'description' => 'Product image ratio (1/1 or 4/5)'],
        ['key' => 'active_collection_handle', 'name' => 'Partna Active Collection Handle', 'type' => 'single_line_text_field', 'description' => 'Handle of Active Products smart collection'],
        ['key' => 'default_collection_handle', 'name' => 'Partna Default Collection Handle', 'type' => 'single_line_text_field', 'description' => 'Handle of Default Products manual collection'],
        ['key' => 'favourites_collection_handle', 'name' => 'Partna Favourites Collection Handle', 'type' => 'single_line_text_field', 'description' => 'Handle of Brand Favourites manual collection'],
        ['key' => 'high_commission_collection_handle', 'name' => 'Partna High Commission Collection Handle', 'type' => 'single_line_text_field', 'description' => 'Handle of High Commission Products smart collection'],
        ['key' => 'setup_complete', 'name' => 'Partna Setup Complete', 'type' => 'boolean', 'description' => 'Whether brand has completed the setup wizard'],
        ['key' => 'theme_tokens', 'name' => 'Partna Theme Tokens', 'type' => 'json', 'description' => 'Extracted CSS design tokens from the brand storefront theme'],
    ];

    /** Populated by handle() — not serialized. */
    private ShopifyAdminClient $client;

    public function __construct(
        public string $integrationId
    ) {
        $this->onQueue('integrations');
    }

    public function handle(ShopifyAdminClient $client): void
    {
        $this->client = $client;
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

            // Create product-level definitions. Two mismatches can force a
            // delete + recreate on existing definitions:
            //   1. useAsCollectionCondition missing but required (existing case)
            //   2. storefront access differs from desired (e.g. affiliate_discount_pct
            //      flipped from [] to PUBLIC_READ so Hydrogen can read it) — Shopify
            //      does not allow mutating `access` on an existing definition.
            // deleteAllAssociatedMetafields defaults to false on deletion, so any
            // existing metafield values on products survive the re-registration.
            foreach (self::PRODUCT_DEFINITIONS as $def) {
                $needsCollectionCondition = in_array($def['key'], self::COLLECTION_CONDITION_KEYS, true);
                $desiredStorefrontAccess = Arr::get($def, 'access.storefront'); // string|null

                if (in_array($def['key'], $existingProductKeys, true)) {
                    $existing = Arr::first($existingProduct, fn ($d) => $d['key'] === $def['key']);

                    $collectionConditionMismatch = $needsCollectionCondition
                        && $existing
                        && ! $existing['useAsCollectionCondition'];

                    $accessMismatch = $existing
                        && ! $this->storefrontAccessMatches($existing['storefrontAccess'] ?? null, $desiredStorefrontAccess);

                    if ($collectionConditionMismatch || $accessMismatch) {
                        Log::info('Deleting metafield definition to recreate with updated config', [
                            'key' => $def['key'],
                            'id' => $existing['id'],
                            'reason_collection_condition' => $collectionConditionMismatch,
                            'reason_access' => $accessMismatch,
                        ]);
                        $this->deleteDefinition($shopDomain, $accessToken, $apiVersion, $existing['id']);
                        // Note: delete + create is non-atomic. If create fails after delete,
                        // the next retry will find the definition missing and recreate it cleanly.
                        // Fall through to create below.
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

        $edges = $response->json('data.metafieldDefinitions.edges', []);

        return array_map(
            static fn (array $edge): array => [
                'id' => (string) Arr::get($edge, 'node.id', ''),
                'key' => (string) Arr::get($edge, 'node.key', ''),
                'useAsCollectionCondition' => (bool) Arr::get($edge, 'node.useAsCollectionCondition', false),
                // null when the definition has no storefront access (Shopify returns
                // the access object but the `storefront` field is omitted in that
                // case); otherwise a string like "PUBLIC_READ".
                'storefrontAccess' => Arr::get($edge, 'node.access.storefront'),
            ],
            is_array($edges) ? $edges : []
        );
    }

    /**
     * True when the storefront access on an existing definition already matches
     * what we want. Shopify normalises missing access to null and we store
     * absence the same way (no 'access' key in our definition array).
     */
    private function storefrontAccessMatches(?string $existing, ?string $desired): bool
    {
        return ($existing === null ? null : (string) $existing) === ($desired === null ? null : (string) $desired);
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
        return $this->client->graphql(
            $shopDomain,
            $accessToken,
            $apiVersion,
            $query,
            $variables,
            $this->timeout,
        );
    }
}
