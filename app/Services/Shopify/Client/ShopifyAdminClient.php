<?php

namespace App\Services\Shopify\Client;

use App\Exceptions\Shopify\ShopifyGraphQLException;
use App\Exceptions\Shopify\ShopifyThrottledException;
use App\Exceptions\Shopify\ShopifyTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Single entry point for all Shopify Admin API calls.
 *
 * Handles:
 *   - GraphQL with throttle-aware in-process retries
 *   - REST with 429/Retry-After honouring
 *   - Bulk operation helpers with per-shop mutex
 *   - Typed exceptions that the queue's backoff() retry can handle
 *
 * Call sites pass shop_domain + access_token + api_version and get back
 * a standard Illuminate Http Response (or a throw). Throttle state is
 * tracked out-of-band via ShopifyBudgetTracker and reconciled from
 * extensions.cost.throttleStatus on every response.
 *
 * NOTE: budget pre-acquisition and THROTTLED retry are wired in the graphql()
 * method — see preAcquireBudget(), reconcileFromResponse(), and isThrottled().
 */
class ShopifyAdminClient
{
    public function __construct(
        private readonly ShopifyBudgetTracker $budget,
        private readonly ShopifyCostTracker $cost,
        private readonly ShopifyMetrics $metrics,
        private readonly ShopifyBulkOperationLock $bulkLock,
    ) {}

    /**
     * Execute a GraphQL request against the Shopify Admin API.
     *
     * Pre-acquires from the local token bucket before sending and reconciles
     * bucket state from the authoritative throttleStatus in the response.
     *
     * @throws ShopifyGraphQLException  top-level GraphQL errors (excluding THROTTLED)
     * @throws ShopifyTransportException non-2xx HTTP, timeout, or connection failure
     */
    public function graphql(
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        string $query,
        array $variables = [],
        ?int $timeoutSeconds = null,
    ): Response {
        $timeout = $timeoutSeconds ?? (int) config('services.shopify.throttle.default_timeout', 20);
        $maxRetries = (int) config('services.shopify.throttle.max_inprocess_retries', 3);
        $maxWait = (int) config('services.shopify.throttle.max_wait_ms', 5000);
        $queryHash = sha1($query);
        $attempt = 0;

        while (true) {
            $this->preAcquireBudget($shopDomain, $queryHash);
            $started = microtime(true);
            $response = $this->post($shopDomain, $accessToken, $apiVersion, $query, $variables, $timeout);
            $this->reconcileFromResponse($response, $shopDomain, $queryHash, $started);

            if ($this->isThrottled($response)) {
                if ($attempt >= $maxRetries) {
                    $wait = $this->throttleWaitMs($response, $maxWait);
                    throw new ShopifyThrottledException($shopDomain, $wait, $attempt, $queryHash);
                }
                $wait = $this->throttleWaitMs($response, $maxWait);
                $this->metrics->throttled($shopDomain, $wait, $attempt + 1);
                // Blocks the worker thread — keep max_inprocess_retries low (default 3).
                usleep($wait * 1000);
                $attempt++;
                continue;
            }

            $this->handleGraphqlErrors($response, $shopDomain, $queryHash);
            return $response;
        }
    }

    private function post(
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        string $query,
        array $variables,
        int $timeout,
    ): Response {
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", array_filter([
                    'query' => $query,
                    'variables' => ! empty($variables) ? $variables : null,
                ]));
        } catch (ConnectionException $e) {
            throw new ShopifyTransportException($shopDomain, 0, $e->getMessage(), $e);
        }

        if (! $response->successful()) {
            throw new ShopifyTransportException($shopDomain, $response->status(), (string) $response->body());
        }

        return $response;
    }

    private function handleGraphqlErrors(Response $response, string $shopDomain, string $queryHash): void
    {
        $errors = $response->json('errors', []);
        if (empty($errors)) {
            return;
        }

        $this->metrics->graphqlError($shopDomain, $queryHash, $errors);
        throw new ShopifyGraphQLException($shopDomain, $errors, $queryHash);
    }

    private function isThrottled(Response $response): bool
    {
        $errors = $response->json('errors', []);
        foreach ($errors as $error) {
            if (($error['extensions']['code'] ?? '') === 'THROTTLED') {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns how long to wait before retrying a THROTTLED response, in milliseconds.
     * Falls back to 1 000 ms (capped at max_wait_ms) when no throttleStatus is available.
     */
    private function throttleWaitMs(Response $response, int $maxWait): int
    {
        $available = $response->json('extensions.cost.throttleStatus.currentlyAvailable');
        $restoreRate = $response->json('extensions.cost.throttleStatus.restoreRate');

        if ($restoreRate > 0 && $available !== null) {
            // Estimate how many ms until 10 points are available (minimum useful budget)
            $needed = max(0, 10 - (int) $available);
            $waitMs = (int) ceil(($needed / $restoreRate) * 1000);
            return min($waitMs, $maxWait);
        }

        return min(1000, $maxWait);
    }

    /**
     * Reserve estimated cost from the local token bucket before sending the request.
     * If the bucket is insufficient, waits up to max_wait_ms then retries once;
     * any remaining deficit is accepted and left to the THROTTLED retry path.
     */
    private function preAcquireBudget(string $shopDomain, string $queryHash): void
    {
        $defaultRequested = (int) config('services.shopify.throttle.default_estimated_cost', 10);
        $estimated = $this->cost->estimate($queryHash, $defaultRequested);
        $max = (int) config('services.shopify.throttle.default_max_capacity', 1000);
        $rate = (int) config('services.shopify.throttle.default_restore_rate', 100);
        $maxWait = (int) config('services.shopify.throttle.max_wait_ms', 5000);

        $result = $this->budget->tryAcquire($shopDomain, $estimated, $max, $rate);

        if (! $result['acquired']) {
            $wait = min($result['wait_ms'], $maxWait);
            $this->metrics->budgetWait($shopDomain, $wait, $estimated);
            usleep($wait * 1000);

            // Retry once after the wait — if still short, proceed anyway and
            // let the THROTTLED retry path handle it.
            // Result is intentionally discarded; we proceed regardless of outcome.
            $this->budget->tryAcquire($shopDomain, $estimated, $max, $rate);
        }
    }

    /**
     * Overwrite local bucket state with Shopify's authoritative throttleStatus
     * and record the actual/requested cost ratio for future estimates.
     */
    private function reconcileFromResponse(Response $response, string $shopDomain, string $queryHash, float $started): void
    {
        $cost = $response->json('extensions.cost');
        if (! is_array($cost)) {
            return;
        }

        $status = $cost['throttleStatus'] ?? [];
        if (isset($status['currentlyAvailable'])) {
            $this->budget->reconcile(
                $shopDomain,
                (int) $status['currentlyAvailable'],
            );
        }

        $requested = (int) ($cost['requestedQueryCost'] ?? 0);
        $actual = (int) ($cost['actualQueryCost'] ?? 0);
        if ($requested > 0 && $actual > 0) {
            $this->cost->record($queryHash, $requested, $actual);
        }

        $this->metrics->request(
            $shopDomain,
            $queryHash,
            (microtime(true) - $started) * 1000,
            $actual,
            (int) ($status['currentlyAvailable'] ?? 0), // 0 when throttleStatus absent — not a true empty-bucket signal
        );
    }
}
