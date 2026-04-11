<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Cache;

class ShopifySetupTokenService
{
    private const CACHE_PREFIX = 'shopify_setup:';
    private const TTL_MINUTES = 60;

    public function create(
        string $shopDomain,
        string $accessToken,
        array $shopData,
        array $scopes,
        string $shopEmail,
    ): string {
        $token = bin2hex(random_bytes(32));

        Cache::put(self::CACHE_PREFIX . $token, [
            'shop_domain' => $shopDomain,
            'access_token' => encrypt($accessToken),
            'shop_data' => $shopData,
            'scopes' => $scopes,
            'shop_email' => $shopEmail,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    public function peek(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $data = Cache::get(self::CACHE_PREFIX . $token);
        if (! is_array($data)) {
            return null;
        }

        return $this->decryptPayload($data);
    }

    public function consume(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $data = Cache::pull(self::CACHE_PREFIX . $token);
        if (! is_array($data)) {
            return null;
        }

        return $this->decryptPayload($data);
    }

    private function decryptPayload(array $data): array
    {
        $data['access_token'] = decrypt($data['access_token']);

        return $data;
    }
}
