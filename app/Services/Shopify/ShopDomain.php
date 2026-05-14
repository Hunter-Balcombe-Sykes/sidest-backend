<?php

namespace App\Services\Shopify;

use App\Exceptions\Shopify\InvalidShopDomainException;
use Stringable;

/**
 * Validated Shopify shop domain (`<handle>.myshopify.com`).
 *
 * Closes the SEC-F#3 finding: every Shopify API caller is forced to construct
 * a ShopDomain at the boundary, so the validation regex can never be skipped
 * the way ShopifyTeardownService and BrandSignupService::revokeStorefrontToken
 * used to skip it when accepting a raw string.
 *
 * Constructor is private. Use {@see ShopDomain::fromUntrusted()} at any point
 * where the input came from the outside world (HTTP, DB, webhook payload, etc).
 */
final class ShopDomain implements Stringable
{
    private const PATTERN = '/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/';

    private function __construct(public readonly string $value) {}

    /**
     * Parse and validate an untrusted string into a ShopDomain.
     *
     * Accepts mixed case and surrounding whitespace (both lowercased/trimmed
     * before validation). Anything that doesn't match the canonical
     * `<handle>.myshopify.com` shape — including paths, ports, embedded hosts,
     * or CRLF injection attempts — is rejected.
     *
     * @throws InvalidShopDomainException
     */
    public static function fromUntrusted(string $input): self
    {
        $normalized = strtolower(trim($input));

        if ($normalized === '' || ! preg_match(self::PATTERN, $normalized)) {
            throw new InvalidShopDomainException($input);
        }

        return new self($normalized);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
