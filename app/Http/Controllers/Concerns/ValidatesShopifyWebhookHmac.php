<?php

namespace App\Http\Controllers\Concerns;

trait ValidatesShopifyWebhookHmac
{
    protected function isValidShopifyHmac(string $rawBody, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $secrets = array_filter([
            (string) config('services.shopify.webhook_secret'),
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
