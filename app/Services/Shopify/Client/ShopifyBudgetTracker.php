<?php

namespace App\Services\Shopify\Client;

use Illuminate\Support\Facades\Redis;

/**
 * Per-shop token bucket for Shopify Admin GraphQL cost budget.
 *
 * Uses a Redis server-side Lua script (invoked via Redis::command('eval', ...))
 * for atomicity — multiple queue workers on the same shop cannot over-commit
 * the bucket. Bucket state is keyed by shop_domain and auto-expires after
 * inactivity.
 *
 * phpredis eval signature: eval($script, $keysAndArgs, $numKeys)
 * Keys come first in $keysAndArgs; ARGV[] are the remaining entries.
 */
class ShopifyBudgetTracker
{
    private const LUA_ACQUIRE = <<<'LUA'
local bucket = redis.call('HMGET', KEYS[1], 'tokens', 'updated_ms')
local tokens = tonumber(bucket[1])
local updated = tonumber(bucket[2])
local max_cap = tonumber(ARGV[1])
local rate = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local cost = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])

if tokens == nil then
    tokens = max_cap
    updated = now
end

local elapsed_sec = (now - updated) / 1000.0
if elapsed_sec < 0 then elapsed_sec = 0 end
tokens = math.min(max_cap, tokens + elapsed_sec * rate)

local acquired = 0
local wait_ms = 0
if tokens >= cost then
    tokens = tokens - cost
    acquired = 1
else
    local deficit = cost - tokens
    wait_ms = math.ceil((deficit / rate) * 1000)
end

redis.call('HMSET', KEYS[1], 'tokens', tokens, 'updated_ms', now)
redis.call('EXPIRE', KEYS[1], ttl)
return {acquired, math.floor(tokens), wait_ms}
LUA;

    private const LUA_RECONCILE = <<<'LUA'
redis.call('HMSET', KEYS[1], 'tokens', ARGV[1], 'updated_ms', ARGV[2])
redis.call('EXPIRE', KEYS[1], ARGV[3])
return 1
LUA;

    /**
     * Attempt to reserve `estimatedCost` points from the shop's bucket.
     *
     * @return array{acquired: bool, remaining: int, wait_ms: int}
     */
    public function tryAcquire(string $shopDomain, int $estimatedCost, int $maxCapacity, int $restoreRate): array
    {
        $ttl = (int) config('services.shopify.throttle.bucket_ttl_seconds', 60);

        // phpredis: eval($script, [$key, ...argv], numKeys=1)
        $result = Redis::command('eval', [
            self::LUA_ACQUIRE,
            [
                $this->key($shopDomain), // KEYS[1]
                $maxCapacity,            // ARGV[1]
                $restoreRate,            // ARGV[2]
                (int) (microtime(true) * 1000), // ARGV[3]
                $estimatedCost,          // ARGV[4]
                $ttl,                    // ARGV[5]
            ],
            1, // numKeys
        ]);

        return [
            'acquired' => (int) $result[0] === 1,
            'remaining' => (int) $result[1],
            'wait_ms' => (int) $result[2],
        ];
    }

    /**
     * Overwrite local bucket state with authoritative Shopify response values.
     * Shopify's throttleStatus is the truth; our local estimate only exists
     * to prevent over-commitment before the first response lands.
     */
    public function reconcile(string $shopDomain, int $currentlyAvailable, int $maximumAvailable, int $restoreRate): void
    {
        $ttl = (int) config('services.shopify.throttle.bucket_ttl_seconds', 60);

        // phpredis: eval($script, [$key, ...argv], numKeys=1)
        Redis::command('eval', [
            self::LUA_RECONCILE,
            [
                $this->key($shopDomain),        // KEYS[1]
                $currentlyAvailable,             // ARGV[1]
                (int) (microtime(true) * 1000), // ARGV[2]
                $ttl,                            // ARGV[3]
            ],
            1, // numKeys
        ]);
    }

    private function key(string $shopDomain): string
    {
        return "shopify:bucket:{$shopDomain}";
    }
}
