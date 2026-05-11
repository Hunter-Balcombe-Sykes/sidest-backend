<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Undoes everything CreateShopify*Job created during OAuth install.
 *
 * Why this exists separately from the webhook:
 *   Shopify revokes our access token BEFORE delivering the app/uninstalled
 *   webhook, so the webhook has no usable token and can only mark local
 *   state. The full Shopify-side cleanup (metafield defs, collections,
 *   automatic discount, storefront token, sales channel) must run while we
 *   still hold a live token — that's what the dashboard "Disconnect" button
 *   calls (ShopifyIntegrationController::disconnect) right before it
 *   invalidates and deletes the local integration row.
 *
 * Behaviour:
 *   - Best-effort per-resource: a failure on one step logs and continues
 *     to the next so a partial outage doesn't block the rest of the sweep.
 *   - Idempotent: if a resource is already gone (or never existed) the step
 *     is a no-op. Safe to re-run.
 *   - Returns a summary describing what was deleted / skipped / failed for
 *     logging + UI feedback.
 */
class ShopifyTeardownService
{
    public function __construct(
        private readonly ShopifyAdminClient $client,
    ) {}

    // Mirrors CreateShopifyMetafieldsJob's definition tables. Any new sidest.*
    // key added there must also be listed here or it'll leak across uninstalls.
    private const PRODUCT_KEYS = ['active', 'commission_override', 'affiliate_discount_pct', 'has_enabled_variants'];

    private const PRODUCT_VARIANT_KEYS = ['enabled'];

    private const SHOP_KEYS = [
        'default_commission_rate', 'accent_color', 'theme_variant', 'product_image_ratio',
        'active_collection_handle', 'default_collection_handle', 'favourites_collection_handle',
        'high_commission_collection_handle', 'setup_complete', 'theme_tokens',
    ];

    // Collection titles CreateShopifyCollectionsJob creates — match verbatim
    // because we look them up by title.
    private const COLLECTION_TITLES = [
        'Partna — Active Products',
        'Partna — Default Products',
        'Partna — Brand Favourites',
        'Partna — High Commission Products',
    ];

    // Publication / sales channel title from CreateShopifySalesChannelJob.
    private const PUBLICATION_TITLES = ['Partna'];

    private const METAFIELD_DEFINITIONS_QUERY = <<<'GRAPHQL'
    query metafieldDefinitions($ownerType: MetafieldOwnerType!, $namespace: String!, $first: Int!) {
      metafieldDefinitions(ownerType: $ownerType, namespace: $namespace, first: $first) {
        edges { node { id key } }
      }
    }
    GRAPHQL;

    // deleteAllAssociatedMetafields: true — merchants disconnecting via the
    // dashboard expect a clean slate. Product metafield values (commission,
    // active flags, etc.) only make sense alongside the app; leaving them
    // behind clutters the merchant's products.
    private const METAFIELD_DEFINITION_DELETE = <<<'GRAPHQL'
    mutation metafieldDefinitionDelete($id: ID!) {
      metafieldDefinitionDelete(id: $id, deleteAllAssociatedMetafields: true) {
        deletedDefinitionId
        userErrors { field message }
      }
    }
    GRAPHQL;

    private const COLLECTIONS_BY_TITLE = <<<'GRAPHQL'
    query collections($query: String!, $first: Int!) {
      collections(query: $query, first: $first) {
        edges { node { id title } }
      }
    }
    GRAPHQL;

    private const COLLECTION_DELETE = <<<'GRAPHQL'
    mutation collectionDelete($input: CollectionDeleteInput!) {
      collectionDelete(input: $input) {
        deletedCollectionId
        userErrors { field message }
      }
    }
    GRAPHQL;

    private const AUTOMATIC_APP_DISCOUNTS_QUERY = <<<'GRAPHQL'
    query automaticAppDiscounts($first: Int!) {
      automaticDiscountNodes(first: $first) {
        edges {
          node {
            id
            automaticDiscount {
              ... on DiscountAutomaticApp {
                appDiscountType { functionId }
              }
            }
          }
        }
      }
    }
    GRAPHQL;

    private const DISCOUNT_AUTOMATIC_DELETE = <<<'GRAPHQL'
    mutation discountAutomaticDelete($id: ID!) {
      discountAutomaticDelete(id: $id) {
        deletedAutomaticDiscountId
        userErrors { field message }
      }
    }
    GRAPHQL;

    private const SHOPIFY_FUNCTIONS_QUERY = <<<'GRAPHQL'
    query shopifyFunctions($first: Int!) {
      shopifyFunctions(first: $first) {
        edges { node { id apiType title } }
      }
    }
    GRAPHQL;

    private const PUBLICATIONS_QUERY = <<<'GRAPHQL'
    query publications($first: Int!) {
      publications(first: $first) {
        edges { node { id name } }
      }
    }
    GRAPHQL;

    private const PUBLICATION_DELETE = <<<'GRAPHQL'
    mutation publicationDelete($id: ID!) {
      publicationDelete(id: $id) {
        deletedId
        userErrors { field message }
      }
    }
    GRAPHQL;

    /**
     * Run the full teardown sweep against the merchant's Shopify store while
     * the token in $integration is still valid. Each step is isolated so a
     * failure on (say) the discount delete doesn't prevent metafield or
     * collection cleanup.
     *
     * @return array{
     *   metafield_definitions: int,
     *   collections: int,
     *   automatic_discounts: int,
     *   storefront_access_tokens: int,
     *   publications: int,
     *   oauth_revoked: bool,
     *   errors: array<int, string>,
     * }
     */
    public function teardownForIntegration(ProfessionalIntegration $integration): array
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        $summary = [
            'metafield_definitions' => 0,
            'collections' => 0,
            'automatic_discounts' => 0,
            'storefront_access_tokens' => 0,
            'publications' => 0,
            'oauth_revoked' => false,
            'errors' => [],
        ];

        if ($shopDomain === '' || $accessToken === '') {
            $summary['errors'][] = 'missing_credentials';

            return $summary;
        }

        // Order matters — discount references the function; deleting discount
        // first means nothing is pointing at the function when we delete the
        // other artifacts. Publications/metafields/collections are
        // independent of each other at this point.
        $this->safeStep($summary, 'automatic_discounts', fn () => $this->deleteAutomaticDiscounts($shopDomain, $accessToken, $apiVersion));
        $this->safeStep($summary, 'collections', fn () => $this->deleteCollections($shopDomain, $accessToken, $apiVersion));
        $this->safeStep($summary, 'metafield_definitions', fn () => $this->deleteMetafieldDefinitions($shopDomain, $accessToken, $apiVersion));
        $this->safeStep($summary, 'storefront_access_tokens', fn () => $this->deleteStorefrontAccessTokens($shopDomain, $accessToken, $apiVersion));
        $this->safeStep($summary, 'publications', fn () => $this->deletePublications($shopDomain, $accessToken, $apiVersion));

        // OAuth revoke is last — anything that needed the token runs before.
        try {
            $summary['oauth_revoked'] = $this->revokeOauthToken($shopDomain, $accessToken);
        } catch (\Throwable $e) {
            $summary['errors'][] = 'oauth_revoke:'.$e->getMessage();
            Log::warning('Shopify teardown: OAuth revoke failed', [
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Shopify teardown complete', [
            'integration_id' => (string) $integration->id,
            'shop_domain' => $shopDomain,
            'summary' => $summary,
        ]);

        return $summary;
    }

    /**
     * Wrap a teardown step in try/catch and record both the delete count and
     * any error so the caller gets a predictable summary even under partial
     * failure.
     *
     * @param  callable(): int  $step  returns count of resources deleted
     */
    private function safeStep(array &$summary, string $key, callable $step): void
    {
        try {
            $summary[$key] = $step();
        } catch (\Throwable $e) {
            $summary['errors'][] = "{$key}:".$e->getMessage();
            Log::warning("Shopify teardown step failed: {$key}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete all automatic app discounts backed by the sidest-affiliate-discount
     * function. Filters by function_id so a brand running multiple apps with
     * app-discounts only loses ours.
     */
    private function deleteAutomaticDiscounts(string $shopDomain, string $accessToken, string $apiVersion): int
    {
        $functionId = $this->resolveSidestFunctionId($shopDomain, $accessToken, $apiVersion);
        if ($functionId === null) {
            // Function not present on this store — nothing could reference it.
            return 0;
        }

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::AUTOMATIC_APP_DISCOUNTS_QUERY, [
            'first' => 50,
        ]);

        $edges = $response->json('data.automaticDiscountNodes.edges', []);
        if (! is_array($edges)) {
            return 0;
        }

        $deleted = 0;
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $nodeFunctionId = Arr::get($node, 'automaticDiscount.appDiscountType.functionId');
            if ($nodeFunctionId !== $functionId) {
                continue;
            }

            $discountId = (string) Arr::get($node, 'id', '');
            if ($discountId === '') {
                continue;
            }

            $deleteRes = $this->graphql($shopDomain, $accessToken, $apiVersion, self::DISCOUNT_AUTOMATIC_DELETE, [
                'id' => $discountId,
            ]);

            $userErrors = $deleteRes->json('data.discountAutomaticDelete.userErrors', []);
            if (empty($userErrors)) {
                $deleted++;
            } else {
                Log::warning('Shopify teardown: automatic discount delete had userErrors', [
                    'discount_id' => $discountId,
                    'errors' => $userErrors,
                ]);
            }
        }

        return $deleted;
    }

    private function resolveSidestFunctionId(string $shopDomain, string $accessToken, string $apiVersion): ?string
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::SHOPIFY_FUNCTIONS_QUERY, [
            'first' => 50,
        ]);

        $edges = $response->json('data.shopifyFunctions.edges', []);
        if (! is_array($edges)) {
            return null;
        }

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if ((string) Arr::get($node, 'apiType', '') !== 'discount') {
                continue;
            }
            if ((string) Arr::get($node, 'title', '') === 'partna-affiliate-discount') {
                return (string) Arr::get($node, 'id', '') ?: null;
            }
        }

        return null;
    }

    /**
     * Delete every Partna smart/manual collection by matching exact titles.
     * Using title rather than storing the GID locally because the
     * CreateShopifyCollectionsJob also keys off title for idempotency —
     * matches the existing convention.
     */
    private function deleteCollections(string $shopDomain, string $accessToken, string $apiVersion): int
    {
        $deleted = 0;

        foreach (self::COLLECTION_TITLES as $title) {
            $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTIONS_BY_TITLE, [
                'query' => "title:'{$title}'",
                'first' => 5,
            ]);

            $edges = $response->json('data.collections.edges', []);
            if (! is_array($edges)) {
                continue;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                // Shopify's search can match partial titles — enforce exact match
                // on our side so we never touch a brand-created collection that
                // happens to contain "Partna" as a substring.
                if ((string) Arr::get($node, 'title', '') !== $title) {
                    continue;
                }

                $collectionId = (string) Arr::get($node, 'id', '');
                if ($collectionId === '') {
                    continue;
                }

                $deleteRes = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTION_DELETE, [
                    'input' => ['id' => $collectionId],
                ]);

                $userErrors = $deleteRes->json('data.collectionDelete.userErrors', []);
                if (empty($userErrors)) {
                    $deleted++;
                } else {
                    Log::warning('Shopify teardown: collection delete had userErrors', [
                        'collection_id' => $collectionId,
                        'title' => $title,
                        'errors' => $userErrors,
                    ]);
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete sidest.* metafield definitions on PRODUCT, PRODUCTVARIANT, and
     * SHOP owner types. Uses deleteAllAssociatedMetafields: true so the
     * values disappear from products/variants too — the goal is a clean
     * store after disconnect, not a half-deleted shell of config.
     */
    private function deleteMetafieldDefinitions(string $shopDomain, string $accessToken, string $apiVersion): int
    {
        $deleted = 0;

        foreach ([
            ['PRODUCT', self::PRODUCT_KEYS],
            ['PRODUCTVARIANT', self::PRODUCT_VARIANT_KEYS],
            ['SHOP', self::SHOP_KEYS],
        ] as [$ownerType, $expectedKeys]) {
            $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELD_DEFINITIONS_QUERY, [
                'ownerType' => $ownerType,
                'namespace' => 'partna',
                'first' => 50,
            ]);

            $edges = $response->json('data.metafieldDefinitions.edges', []);
            if (! is_array($edges)) {
                continue;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $key = (string) Arr::get($node, 'key', '');
                $id = (string) Arr::get($node, 'id', '');

                // Namespace filter guarantees we only look at sidest.* but the
                // belt-and-braces key check here stops us deleting anything
                // another Partna feature may add in the future without
                // tracking here first. Anything we don't recognise is logged
                // but NOT deleted.
                if (! in_array($key, $expectedKeys, true)) {
                    Log::info('Shopify teardown: skipping unknown sidest metafield definition', [
                        'owner_type' => $ownerType,
                        'key' => $key,
                    ]);

                    continue;
                }

                if ($id === '') {
                    continue;
                }

                $deleteRes = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELD_DEFINITION_DELETE, [
                    'id' => $id,
                ]);

                $userErrors = $deleteRes->json('data.metafieldDefinitionDelete.userErrors', []);
                if (empty($userErrors)) {
                    $deleted++;
                } else {
                    Log::warning('Shopify teardown: metafield definition delete had userErrors', [
                        'id' => $id,
                        'owner_type' => $ownerType,
                        'key' => $key,
                        'errors' => $userErrors,
                    ]);
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete storefront access tokens this app created. Admin GraphQL
     * removed the query/mutation for these in 2025-01, so we fall back to
     * the REST endpoint — matches how CreateStorefrontAccessTokenJob
     * enumerates them.
     */
    private function deleteStorefrontAccessTokens(string $shopDomain, string $accessToken, string $apiVersion): int
    {
        try {
            $response = $this->client->rest(
                method: 'GET',
                shopDomain: $shopDomain,
                accessToken: $accessToken,
                path: "/admin/api/{$apiVersion}/storefront_access_tokens.json",
                timeoutSeconds: 15,
            );
        } catch (\App\Exceptions\Shopify\ShopifyTransportException $e) {
            return 0;
        }

        $tokens = $response->json('storefront_access_tokens', []);
        if (! is_array($tokens)) {
            return 0;
        }

        $deleted = 0;
        foreach ($tokens as $token) {
            $id = (string) Arr::get($token, 'id', '');
            $title = (string) Arr::get($token, 'title', '');

            // Only delete tokens we created. CreateStorefrontAccessTokenJob
            // tags these with a "Partna" title — skip anything else so we
            // don't nuke a merchant's own integrations.
            if ($title !== 'Partna') {
                continue;
            }
            if ($id === '') {
                continue;
            }

            try {
                $deleteRes = $this->client->rest(
                    method: 'DELETE',
                    shopDomain: $shopDomain,
                    accessToken: $accessToken,
                    path: "/admin/api/{$apiVersion}/storefront_access_tokens/{$id}.json",
                    timeoutSeconds: 15,
                );
                $deleted++;
            } catch (\App\Exceptions\Shopify\ShopifyTransportException $e) {
                Log::warning('Shopify teardown: storefront access token delete failed', [
                    'token_id' => $id,
                    'status' => $e->status,
                ]);
            }
        }

        return $deleted;
    }

    /**
     * Delete the Partna sales channel publication. Matches on title to
     * mirror the create-side logic.
     */
    private function deletePublications(string $shopDomain, string $accessToken, string $apiVersion): int
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::PUBLICATIONS_QUERY, [
            'first' => 50,
        ]);

        $edges = $response->json('data.publications.edges', []);
        if (! is_array($edges)) {
            return 0;
        }

        $deleted = 0;
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if (! in_array((string) Arr::get($node, 'name', ''), self::PUBLICATION_TITLES, true)) {
                continue;
            }

            $id = (string) Arr::get($node, 'id', '');
            if ($id === '') {
                continue;
            }

            $deleteRes = $this->graphql($shopDomain, $accessToken, $apiVersion, self::PUBLICATION_DELETE, [
                'id' => $id,
            ]);

            $userErrors = $deleteRes->json('data.publicationDelete.userErrors', []);
            if (empty($userErrors)) {
                $deleted++;
            } else {
                Log::warning('Shopify teardown: publication delete had userErrors', [
                    'publication_id' => $id,
                    'errors' => $userErrors,
                ]);
            }
        }

        return $deleted;
    }

    /**
     * Revoke the app's OAuth access token so Shopify immediately marks the
     * app as uninstalled from the merchant's perspective. The subsequent
     * app/uninstalled webhook will fire and hit our local-only handler.
     */
    private function revokeOauthToken(string $shopDomain, string $accessToken): bool
    {
        try {
            $response = $this->client->rest(
                method: 'DELETE',
                shopDomain: $shopDomain,
                accessToken: $accessToken,
                path: '/admin/api_permissions/current.json',
                timeoutSeconds: 15,
                allow401: true,
            );

            return $response->successful() || $response->status() === 401;
        } catch (\App\Exceptions\Shopify\ShopifyTransportException $e) {
            return false;
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
        );
    }
}
