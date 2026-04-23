<?php

namespace App\Exceptions\Shopify;

class ShopifyTransportException extends ShopifyClientException
{
    public function __construct(
        string $shopDomain,
        public readonly int $status,
        public readonly string $body,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Shopify transport error on {$shopDomain} (HTTP {$status})",
            $shopDomain,
            null,
            $previous,
        );
    }
}
