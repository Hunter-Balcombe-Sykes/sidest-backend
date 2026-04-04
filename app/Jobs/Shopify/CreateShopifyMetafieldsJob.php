<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V2: Creates sidest.* metafield definitions on the brand's Shopify store. Idempotent — skips existing definitions.
class CreateShopifyMetafieldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    private const METAFIELD_DEFINITION_CREATE = <<<'GRAPHQL'
    mutation metafieldDefinitionCreate($definition: MetafieldDefinitionInput!) {
      metafieldDefinitionCreate(definition: $definition) {
        createdDefinition {
          id
          namespace
          key
        }
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
          }
        }
      }
    }
    GRAPHQL;

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

        if ($shopDomain === '' || $accessToken === '') {
            $integration->mergeProviderMetadata(['metafield_definitions_state' => 'failed']);

            return;
        }

        try {
            // Get existing definitions to avoid duplicates
            $existingProduct = $this->getExistingKeys($shopDomain, $accessToken, $apiVersion, 'PRODUCT');
            $existingShop = $this->getExistingKeys($shopDomain, $accessToken, $apiVersion, 'SHOP');

            // Create product-level definitions
            foreach (self::PRODUCT_DEFINITIONS as $def) {
                if (in_array($def['key'], $existingProduct, true)) {
                    continue;
                }
                $this->createDefinition($shopDomain, $accessToken, $apiVersion, 'PRODUCT', $def);
            }

            // Create shop-level definitions
            foreach (self::SHOP_DEFINITIONS as $def) {
                if (in_array($def['key'], $existingShop, true)) {
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

            $integration->mergeProviderMetadata(['metafield_definitions_state' => 'failed']);
        }
    }

    /**
     * @return string[] Existing metafield keys for the given owner type
     */
    private function getExistingKeys(string $shopDomain, string $accessToken, string $apiVersion, string $ownerType): array
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post(
            "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
            [
                'query' => self::METAFIELD_DEFINITIONS_QUERY,
                'variables' => [
                    'ownerType' => $ownerType,
                    'namespace' => 'sidest',
                    'first' => 50,
                ],
            ]
        );

        if (! $response->successful()) {
            return [];
        }

        $edges = $response->json('data.metafieldDefinitions.edges', []);

        return array_map(
            static fn (array $edge): string => (string) Arr::get($edge, 'node.key', ''),
            is_array($edges) ? $edges : []
        );
    }

    private function createDefinition(string $shopDomain, string $accessToken, string $apiVersion, string $ownerType, array $def): void
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

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post(
            "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
            [
                'query' => self::METAFIELD_DEFINITION_CREATE,
                'variables' => ['definition' => $definition],
            ]
        );

        $userErrors = $response->json('data.metafieldDefinitionCreate.userErrors', []);
        if (! empty($userErrors)) {
            Log::warning('Shopify metafield definition creation had errors', [
                'key' => $def['key'],
                'owner_type' => $ownerType,
                'errors' => $userErrors,
            ]);
        }
    }
}
