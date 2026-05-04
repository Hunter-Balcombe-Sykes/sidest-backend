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

// V2: Creates four Side St collections on the brand's Shopify store and writes handles to shop metafields.
class CreateShopifyCollectionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

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

    private const PUBLISHABLE_PUBLISH = <<<'GRAPHQL'
    mutation publishablePublish($id: ID!, $input: [PublicationInput!]!) {
      publishablePublish(id: $id, input: $input) {
        publishable { publishedOnCurrentPublication }
        userErrors { field message }
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

    // Smart collections use `appliedDisjunctively: false` → rules are ANDed.
    // Active + High Commission both include sidest.has_enabled_variants=true so a
    // product with every variant disabled falls out of affiliate-facing surfaces
    // automatically, even if the brand never touched sidest.active.
    //
    // Note on existing brands: findOrCreateCollection skips when a collection with
    // the target title already exists, so updating this array only affects newly
    // connected brands. Existing stores are reconciled by the
    // backfill:has-enabled-variants artisan command (Phase 11).
    private const COLLECTIONS = [
        [
            'title' => 'Side St — Active Products',
            'metafield_key' => 'active_collection_handle',
            'smart' => true,
            'rules' => [
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'sidest.active'],
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'sidest.has_enabled_variants'],
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
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'sidest.has_enabled_variants'],
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'GREATER_THAN', 'condition' => '0', 'metafield_ref' => 'sidest.commission_override'],
            ],
        ],
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
            $integration->mergeProviderMetadata(['collections_state' => 'failed']);

            return;
        }

        try {
            $metafieldsToSet = [];
            $collectionGids = [];
            // Publish through the app's own publication (created by CreateShopifySalesChannelJob)
            // so collections are visible via the Storefront API. Fall back to the Online Store
            // publication for legacy integrations that predate the app publication flow.
            $publicationId = Arr::get($metadata, 'publication_id')
                ?: $this->findOnlineStorePublicationId($shopDomain, $accessToken, $apiVersion);

            $shopGid = $this->getShopGid($shopDomain, $accessToken, $apiVersion);
            if ($shopGid === '') {
                throw new \RuntimeException('Could not resolve Shop GID.');
            }

            foreach (self::COLLECTIONS as $collectionDef) {
                $result = $this->findOrCreateCollection(
                    $shopDomain, $accessToken, $apiVersion, $collectionDef
                );

                if ($result !== null) {
                    $metafieldsToSet[] = [
                        'namespace' => 'sidest',
                        'key' => $collectionDef['metafield_key'],
                        'value' => $result['handle'],
                        'type' => 'single_line_text_field',
                        'ownerId' => $shopGid,
                    ];
                    $collectionGids[] = $result['gid'];
                }
            }

            // Publish collections to the app's sales channel so they're visible via Storefront API
            if ($publicationId && ! empty($collectionGids)) {
                $this->publishCollections($shopDomain, $accessToken, $apiVersion, $collectionGids, $publicationId);
            }

            // Write collection handles to shop metafields
            if (! empty($metafieldsToSet)) {
                $this->setMetafields($shopDomain, $accessToken, $apiVersion, $metafieldsToSet);
            }

            // Track partial failures — 'registered' only if all collections succeeded
            $expectedCount = count(self::COLLECTIONS);
            $actualCount = count($collectionGids);
            if ($actualCount === 0) {
                $collectionsState = 'failed';
            } elseif ($actualCount < $expectedCount) {
                $collectionsState = 'partial';
            } else {
                $collectionsState = 'registered';
            }

            // Also store handles in provider_metadata for the storefront config API
            $handlesMeta = ['collections_state' => $collectionsState];
            foreach ($metafieldsToSet as $mf) {
                $handlesMeta[$mf['key']] = $mf['value'];
            }
            $integration->mergeProviderMetadata($handlesMeta);

            Log::info('Shopify collections created', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
            ]);

            // Collections are the last structural dependency of the Side St
            // Price automatic discount (we want collections in place before
            // the function starts firing, so "Active Products" behaviour is
            // coherent the moment the discount activates). Dispatch the
            // install job — idempotent, safe to re-run on retries.
            CreateShopifyAffiliateDiscountJob::dispatch($this->integrationId);
        } catch (\Throwable $e) {
            Log::error('Failed to create Shopify collections', [
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
        $integration?->mergeProviderMetadata(['collections_state' => 'failed']);
    }

    /**
     * @return array{handle: string, gid: string}|null
     */
    private function findOrCreateCollection(string $shopDomain, string $accessToken, string $apiVersion, array $def): ?array
    {
        // Check if collection already exists by title
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTIONS_QUERY, [
            'query' => "title:'{$def['title']}'",
            'first' => 1,
        ]);

        $edges = $response->json('data.collections.edges', []);
        if (! empty($edges)) {
            $handle = (string) Arr::get($edges[0], 'node.handle', '');
            $gid = (string) Arr::get($edges[0], 'node.id', '');

            if ($handle === '' || $gid === '') {
                Log::warning('Existing collection has empty handle or gid', [
                    'title' => $def['title'],
                    'handle' => $handle,
                    'gid' => $gid,
                ]);

                return null;
            }

            return ['handle' => $handle, 'gid' => $gid];
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

        // client throws ShopifyGraphQLException on top-level errors — check userErrors only.
        $userErrors = $response->json('data.collectionCreate.userErrors', []);
        if (! empty($userErrors)) {
            Log::warning('Shopify collection creation had userErrors', [
                'title' => $def['title'],
                'errors' => $userErrors,
            ]);

            return null;
        }

        $collection = $response->json('data.collectionCreate.collection', []);
        $handle = (string) ($collection['handle'] ?? '');
        $gid = (string) ($collection['id'] ?? '');

        if ($handle === '' || $gid === '') {
            Log::warning('Collection created but handle or gid is empty', [
                'title' => $def['title'],
                'handle' => $handle,
                'gid' => $gid,
            ]);

            return null;
        }

        Log::info('Shopify collection created', [
            'title' => $def['title'],
            'handle' => $handle,
            'gid' => $gid,
        ]);

        return ['handle' => $handle, 'gid' => $gid];
    }

    /**
     * Publish collections to the app's sales channel so they're visible via Storefront API.
     */
    private function publishCollections(string $shopDomain, string $accessToken, string $apiVersion, array $collectionGids, string $publicationId): void
    {
        foreach ($collectionGids as $gid) {
            $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::PUBLISHABLE_PUBLISH, [
                'id' => $gid,
                'input' => [['publicationId' => $publicationId]],
            ]);

            $userErrors = $response->json('data.publishablePublish.userErrors', []);
            if (! empty($userErrors)) {
                Log::warning('Failed to publish collection to sales channel', [
                    'collection_gid' => $gid,
                    'publication_id' => $publicationId,
                    'errors' => $userErrors,
                ]);
            }
        }

        Log::info('Collections published to sales channel', [
            'count' => count($collectionGids),
            'publication_id' => $publicationId,
        ]);
    }

    private array $metafieldDefinitionCache = [];

    /**
     * Resolve a metafield reference (e.g. "sidest.active") to its Shopify MetafieldDefinition GID.
     */
    private function resolveMetafieldDefinitionGid(string $shopDomain, string $accessToken, string $apiVersion, string $metafieldRef): ?string
    {
        if (isset($this->metafieldDefinitionCache[$metafieldRef])) {
            return $this->metafieldDefinitionCache[$metafieldRef];
        }

        [$namespace, $key] = explode('.', $metafieldRef, 2);

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELD_DEFINITION_QUERY, [
            'ownerType' => 'PRODUCT',
            'namespace' => $namespace,
            'first' => 25,
        ]);

        $edges = $response->json('data.metafieldDefinitions.edges', []);

        // Cache all definitions from this namespace
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $cacheKey = ($node['namespace'] ?? '').'.'.($node['key'] ?? '');
            $this->metafieldDefinitionCache[$cacheKey] = $node['id'] ?? null;
        }

        return $this->metafieldDefinitionCache[$metafieldRef] ?? null;
    }

    /**
     * Find the Online Store publication ID so collections can be published to it.
     */
    private function findOnlineStorePublicationId(string $shopDomain, string $accessToken, string $apiVersion): ?string
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, '
            query { publications(first: 20) { edges { node { id name } } } }
        ', []);

        $edges = $response->json('data.publications.edges', []);

        foreach ($edges as $edge) {
            $name = strtolower(trim((string) Arr::get($edge, 'node.name', '')));
            if ($name === 'online store') {
                return (string) Arr::get($edge, 'node.id', '');
            }
        }

        // No "Online Store" found — don't fall back to an arbitrary channel (could be POS)
        if (! empty($edges)) {
            Log::warning('Online Store publication not found, skipping collection publishing', [
                'integration_id' => $this->integrationId,
                'available_publications' => collect($edges)->pluck('node.name')->toArray(),
            ]);
        }

        return null;
    }

    private function setMetafields(string $shopDomain, string $accessToken, string $apiVersion, array $metafields): void
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELDS_SET, [
            'metafields' => $metafields,
        ]);

        $userErrors = $response->json('data.metafieldsSet.userErrors', []);
        if (! empty($userErrors)) {
            Log::warning('Shopify setMetafields had userErrors', [
                'integration_id' => $this->integrationId,
                'errors' => $userErrors,
            ]);
        }
    }

    private function getShopGid(string $shopDomain, string $accessToken, string $apiVersion): string
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, '{ shop { id } }', []);

        return (string) $response->json('data.shop.id', '');
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
