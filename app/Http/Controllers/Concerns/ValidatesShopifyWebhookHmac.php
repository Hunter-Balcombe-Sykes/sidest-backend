<?php

namespace App\Http\Controllers\Concerns;

// V2: Validates Shopify webhook HMAC-SHA256 signatures against primary and fallback secrets for secure webhook ingestion.
trait ValidatesShopifyWebhookHmac
{
    protected function isValidShopifyHmac(string $rawBody, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $secrets = array_filter([
            (string) config('services.shopify.webhook_secret'),
            // Fallback secret supports zero-downtime rotation: set it to the old webhook_secret
            // when rotating, then CLEAR it within 30 days once all in-flight webhooks have drained.
            (string) config('services.shopify.fallback_secret'),
        ], static fn (string $s): bool => $s !== '');

        foreach ($secrets as $secret) {
            $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
