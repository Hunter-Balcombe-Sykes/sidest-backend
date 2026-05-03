<?php

namespace App\Exceptions\Shopify;

use RuntimeException;

abstract class ShopifyClientException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $shopDomain,
        public readonly ?string $queryHash = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
