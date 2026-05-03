<?php

namespace App\Services\Shopify\Client;

use Illuminate\Support\Facades\Log;

/**
 * Observability surface for Shopify Admin client events.
 *
 * Intentionally thin — just structured logs with a consistent `shopify.client.*`
 * prefix so Nightwatch/Grafana/log filters can pick them up. Swap the body
 * for a StatsD/Prometheus emitter later without touching callers.
 */
class ShopifyMetrics
{
    public function throttled(string $shopDomain, int $waitMs, int $attempt): void
    {
        Log::warning('shopify.client.throttled', [
            'shop_domain' => $shopDomain,
            'wait_ms' => $waitMs,
            'attempt' => $attempt,
        ]);
    }

    public function request(string $shopDomain, string $queryHash, float $durationMs, int $actualCost, int $bucketRemaining): void
    {
        Log::debug('shopify.client.request', [
            'shop_domain' => $shopDomain,
            'query_hash' => $queryHash,
            'duration_ms' => (int) $durationMs,
            'actual_cost' => $actualCost,
            'bucket_remaining' => $bucketRemaining,
        ]);
    }

    public function budgetWait(string $shopDomain, int $waitMs, int $estimatedCost): void
    {
        Log::info('shopify.client.budget_wait', [
            'shop_domain' => $shopDomain,
            'wait_ms' => $waitMs,
            'estimated_cost' => $estimatedCost,
        ]);
    }

    public function bulkLockContended(string $shopDomain): void
    {
        Log::warning('shopify.client.bulk_lock_contended', [
            'shop_domain' => $shopDomain,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function graphqlError(string $shopDomain, string $queryHash, array $errors): void
    {
        Log::error('shopify.client.graphql_error', [
            'shop_domain' => $shopDomain,
            'query_hash' => $queryHash,
            'errors' => $errors,
        ]);
    }
}
