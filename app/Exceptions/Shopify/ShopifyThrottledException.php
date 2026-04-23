<?php

namespace App\Exceptions\Shopify;

class ShopifyThrottledException extends ShopifyClientException
{
    public function __construct(
        string $shopDomain,
        public readonly int $waitMs,
        public readonly int $attempts,
        ?string $queryHash = null,
    ) {
        parent::__construct(
            "Shopify throttled {$shopDomain} after {$attempts} in-process retries (needed {$waitMs}ms wait)",
            $shopDomain,
            $queryHash,
        );
    }
}
