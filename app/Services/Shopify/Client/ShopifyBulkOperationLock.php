<?php

namespace App\Services\Shopify\Client;

use Illuminate\Support\Facades\Redis;

/**
 * Per-shop mutex for Shopify bulk operations.
 *
 * Shopify enforces "only one bulk operation in flight per shop at a time"
 * at the API level — a second bulkOperationRunMutation call while one is
 * running returns a userError. This lock lets our side catch the conflict
 * before hitting Shopify.
 */
class ShopifyBulkOperationLock
{
    public function acquire(string $shopDomain, ?int $ttlSeconds = null): bool
    {
        $ttl = $ttlSeconds ?? (int) config('services.shopify.throttle.bulk_lock_ttl_seconds', 3600);
        $result = Redis::set($this->key($shopDomain), '1', 'EX', $ttl, 'NX');

        return $result === true || $result === 'OK';
    }

    public function release(string $shopDomain): void
    {
        Redis::del($this->key($shopDomain));
    }

    private function key(string $shopDomain): string
    {
        return "shopify:bulk_lock:{$shopDomain}";
    }
}
