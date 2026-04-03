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
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'sidest.active:true'],
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
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'sidest.active:true'],
                ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'IS_SET', 'condition' => 'sidest.commission_override'],
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
            $metadata['collections_state'] = 'failed';
            $integration->provider_metadata = $metadata;
            $integration->save();
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

            $metadata['collections_state'] = 'registered';

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

            $metadata['collections_state'] = 'failed';
        }

        $integration->provider_metadata = $metadata;
        $integration->save();
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
            $input['ruleSet'] = [
                'appliedDisjunctively' => false,
                'rules' => $def['rules'],
            ];
        }

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTION_CREATE, [
            'input' => $input,
        ]);

        $userErrors = $response->json('data.collectionCreate.userErrors', []);
        if (! empty($userErrors)) {
            Log::warning('Shopify collection creation had errors', [
                'title' => $def['title'],
                'errors' => $userErrors,
            ]);
            return null;
        }

        return (string) $response->json('data.collectionCreate.collection.handle', '');
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
