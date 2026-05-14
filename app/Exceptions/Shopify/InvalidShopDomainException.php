<?php

namespace App\Exceptions\Shopify;

use InvalidArgumentException;

/**
 * Thrown when a string fails ShopDomain validation. Holds the offending input
 * for debugging (NOT for echoing back to the user — the input is untrusted).
 */
final class InvalidShopDomainException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $input,
        ?string $reason = null,
    ) {
        parent::__construct(
            $reason ?? "Invalid Shopify shop domain: {$input}"
        );
    }
}
