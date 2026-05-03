<?php

namespace App\Exceptions\Shopify;

class ShopifyGraphQLException extends ShopifyClientException
{
    /**
     * @param  array<int, array<string, mixed>>  $graphqlErrors
     */
    public function __construct(
        string $shopDomain,
        public readonly array $graphqlErrors,
        ?string $queryHash = null,
    ) {
        $first = $graphqlErrors[0]['message'] ?? 'unknown';
        parent::__construct(
            "Shopify GraphQL error on {$shopDomain}: {$first}",
            $shopDomain,
            $queryHash,
        );
    }
}
