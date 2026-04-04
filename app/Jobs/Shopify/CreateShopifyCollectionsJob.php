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

// V2: Creates four Side St collections on the brand's Shopify store and writes handles to shop metafields.
class CreateShopifyCollectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    private const COLLECTION_CREATE = <<<'GRAPHQL'
    mutation collectionCreate($input: CollectionInput!) {
      collectionCreate(input: $input) {
        collection {
          id
          handle
          title
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    private const COLLECTIONS_QUERY = <<<'GRAPHQL'
    query collections($query: String!, $first: Int!) {
      collections(query: $query, first: $first) {
        edges {
          node {
            id
            handle
            title
          }
        }
      }
    }
    GRAPHQL;

    // Looks up a metafield definition GID by namespace, key, and owner type.
    private const METAFIELD_DEFINITION_QUERY = <<<'GRAPHQL'
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

    private const METAFIELDS_SET = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields {
          id
          namespace
          key
          value
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    private const COLLECTIONS = [
        [
            'title' => 'Side St — Active Products',
            'metafield_key' => 'active_collection_handle',
            'smart' => true,
            'rules' => [
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'sidest.active'],
            ],
        ],
        [
            'title' => 'Side St — Default Products',
            'metafield_key' => 'default_collection_handle',
            'smart' => false,
            'rules' => [],
        ],
        [
            'title' => 'Side St — Brand Favourites',
            'metafield_key' => 'favourites_collection_handle',
            'smart' => false,
            'rules' => [],
        ],
        [
            'title' => 'Side St — High Commission Products',
            'metafield_key' => 'high_commission_collection_handle',
            'smart' => true,
            'rules' => [
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'sidest.active'],
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'GREATER_THAN', 'condition' => '0', 'metafield_ref' => 'sidest.commission_override'],
            ],
        ],
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
            $integration->mergeProviderMetadata(['collections_state' => 'failed']);

            return;
        }

        try {
            $metafieldsToSet = [];

            foreach (self::COLLECTIONS as $collectionDef) {
                $handle = $this->findOrCreateCollection(
                    $shopDomain, $accessToken, $apiVersion, $collectionDef
                );

                if ($handle !== null) {
                    $metafieldsToSet[] = [
                        'namespace' => 'sidest',
                        'key' => $collectionDef['metafield_key'],
                        'value' => $handle,
                        'type' => 'single_line_text_field',
                        'ownerId' => $this->getShopGid($shopDomain, $accessToken, $apiVersion),
                    ];
                }
            }

            // Write collection handles to shop metafields
            if (! empty($metafieldsToSet)) {
                $this->setMetafields($shopDomain, $accessToken, $apiVersion, $metafieldsToSet);
            }

            $integration->mergeProviderMetadata(['collections_state' => 'registered']);

            Log::info('Shopify collections created', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create Shopify collections', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            $integration->mergeProviderMetadata(['collections_state' => 'failed']);
        }
    }

    private function findOrCreateCollection(string $shopDomain, string $accessToken, string $apiVersion, array $def): ?string
    {
        // Check if collection already exists by title
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTIONS_QUERY, [
            'query' => "title:'{$def['title']}'",
            'first' => 1,
        ]);

        $edges = $response->json('data.collections.edges', []);
        if (! empty($edges)) {
            return (string) Arr::get($edges[0], 'node.handle', '');
        }

        // Create the collection
        $input = ['title' => $def['title']];

        if ($def['smart'] && ! empty($def['rules'])) {
            // Resolve metafield definition GIDs for PRODUCT_METAFIELD_DEFINITION rules
            $resolvedRules = [];
            foreach ($def['rules'] as $rule) {
                $graphqlRule = [
                    'column' => $rule['column'],
                    'relation' => $rule['relation'],
                    'condition' => $rule['condition'],
                ];

                if ($rule['column'] === 'PRODUCT_METAFIELD_DEFINITION' && ! empty($rule['metafield_ref'])) {
                    $definitionGid = $this->resolveMetafieldDefinitionGid(
                        $shopDomain, $accessToken, $apiVersion, $rule['metafield_ref']
                    );

                    if ($definitionGid === null) {
                        Log::warning('Could not resolve metafield definition GID', [
                            'title' => $def['title'],
                            'metafield_ref' => $rule['metafield_ref'],
                        ]);

                        return null;
                    }

                    $graphqlRule['conditionObjectId'] = $definitionGid;
                }

                $resolvedRules[] = $graphqlRule;
            }

            $input['ruleSet'] = [
                'appliedDisjunctively' => false,
                'rules' => $resolvedRules,
            ];
        }

        Log::info('Creating Shopify collection', [
            'title' => $def['title'],
            'input' => $input,
        ]);

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTION_CREATE, [
            'input' => $input,
        ]);

        // Check for GraphQL-level errors (not userErrors)
        $graphqlErrors = $response->json('errors', []);
        if (! empty($graphqlErrors)) {
            Log::warning('Shopify collection creation GraphQL errors', [
                'title' => $def['title'],
                'errors' => $graphqlErrors,
            ]);

            return null;
        }

        $userErrors = $response->json('data.collectionCreate.userErrors', []);
        if (! empty($userErrors)) {
            Log::warning('Shopify collection creation had userErrors', [
                'title' => $def['title'],
                'errors' => $userErrors,
                'response_body' => $response->body(),
            ]);

            return null;
        }

        $handle = (string) $response->json('data.collectionCreate.collection.handle', '');

        Log::info('Shopify collection created', [
            'title' => $def['title'],
            'handle' => $handle,
        ]);

        return $handle;
    }

    /**
     * Resolve a metafield reference (e.g. "sidest.active") to its Shopify MetafieldDefinition GID.
     */
    private function resolveMetafieldDefinitionGid(string $shopDomain, string $accessToken, string $apiVersion, string $metafieldRef): ?string
    {
        [$namespace, $key] = explode('.', $metafieldRef, 2);

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELD_DEFINITION_QUERY, [
            'ownerType' => 'PRODUCT',
            'namespace' => $namespace,
            'first' => 25,
        ]);

        $edges = $response->json('data.metafieldDefinitions.edges', []);

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if (($node['namespace'] ?? '') === $namespace && ($node['key'] ?? '') === $key) {
                return $node['id'];
            }
        }

        return null;
    }

    private function setMetafields(string $shopDomain, string $accessToken, string $apiVersion, array $metafields): void
    {
        $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELDS_SET, [
            'metafields' => $metafields,
        ]);
    }

    private function getShopGid(string $shopDomain, string $accessToken, string $apiVersion): string
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, '{ shop { id } }', []);

        return (string) $response->json('data.shop.id', '');
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
