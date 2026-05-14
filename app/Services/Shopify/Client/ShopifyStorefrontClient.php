<?php

namespace App\Services\Shopify\Client;

use App\Exceptions\Shopify\ShopifyGraphQLException;
use App\Exceptions\Shopify\ShopifyThrottledException;
use App\Exceptions\Shopify\ShopifyTransportException;
use App\Services\Shopify\ShopDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Single entry point for Shopify Storefront GraphQL API calls.
 *
 * Storefront has a separate cost budget from Admin at Shopify's edge — same
 * THROTTLED response shape, different bucket. This client mirrors
 * ShopifyAdminClient::graphql() but uses a dedicated ShopifyBudgetTracker
 * (constructed with the `shopify:storefront-bucket` key prefix in the service
 * provider) and a shared ShopifyCostTracker (cost samples are keyed by sha1
 * of the query so admin/storefront samples are naturally namespaced).
 *
 * Why a parallel client and not a flag on ShopifyAdminClient: the Storefront
 * endpoint URL, header name, and budget bucket all differ. Combining them
 * would mean conditionals on every call. The cost is a small amount of
 * duplication for clean separation.
 */
class ShopifyStorefrontClient
{
    public function __construct(
        private readonly ShopifyBudgetTracker $budget,
        private readonly ShopifyCostTracker $cost,
        private readonly ShopifyMetrics $metrics,
    ) {}

    /**
     * Execute a GraphQL request against the Shopify Storefront API.
     *
     * Pre-acquires from the local Storefront token bucket before sending and
     * reconciles bucket state from any throttleStatus in the response.
     *
     * @throws ShopifyGraphQLException top-level GraphQL errors (excluding THROTTLED)
     * @throws ShopifyThrottledException THROTTLED response after one immediate retry
     * @throws ShopifyTransportException non-2xx HTTP, timeout, or connection failure
     */
    public function graphql(
        ShopDomain $shop,
        string $storefrontToken,
        string $apiVersion,
        string $query,
        array $variables = [],
        ?int $timeoutSeconds = null,
    ): Response {
        $timeout = $timeoutSeconds ?? (int) config('services.shopify.throttle.default_timeout', 20);
        $queryHash = sha1($query);
        $shopDomain = $shop->value;

        // One immediate retry without sleep — absorbs single-packet transient
        // blips where the bucket refills before a queue round-trip. Anything
        // beyond that throws and lets the job's backoff() handle the delay.
        $maxImmediateRetries = 1;
        $attempt = 0;

        while (true) {
            $this->preAcquireBudget($shopDomain, $queryHash);
            $started = microtime(true);
            $response = $this->post($shop, $storefrontToken, $apiVersion, $query, $variables, $timeout);
            $this->reconcileFromResponse($response, $shopDomain, $queryHash, $started);

            if ($this->isThrottled($response)) {
                $wait = $this->throttleWaitMs($response);
                if ($attempt >= $maxImmediateRetries) {
                    throw new ShopifyThrottledException($shopDomain, $wait, $attempt, $queryHash);
                }
                // No usleep — same rationale as ShopifyAdminClient. Record
                // the would-be wait for observability even though we don't
                // sleep on it.
                $this->metrics->throttled($shopDomain, $wait, $attempt + 1);
                $attempt++;

                continue;
            }

            $this->handleGraphqlErrors($response, $shopDomain, $queryHash);

            return $response;
        }
    }

    private function post(
        ShopDomain $shop,
        string $storefrontToken,
        string $apiVersion,
        string $query,
        array $variables,
        int $timeout,
    ): Response {
        $shopDomain = $shop->value;

        try {
            $response = Http::withHeaders([
                'X-Shopify-Storefront-Access-Token' => $storefrontToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout($timeout)
                ->post("https://{$shopDomain}/api/{$apiVersion}/graphql.json", array_filter([
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
     * Storefront's throttle response shape mirrors Admin's when present. The
     * Storefront API's request-rate limiter doesn't always emit `extensions.cost`,
     * so we fall back to 1 000 ms (capped by max_wait_ms) — matches the Admin
     * client's `throttleWaitMs()` default for the same reason.
     */
    private function throttleWaitMs(Response $response): int
    {
        $maxWait = (int) config('services.shopify.throttle.max_wait_ms', 5000);
        $available = $response->json('extensions.cost.throttleStatus.currentlyAvailable');
        $restoreRate = $response->json('extensions.cost.throttleStatus.restoreRate');

        if ($restoreRate > 0 && $available !== null) {
            $needed = max(0, 10 - (int) $available);
            $waitMs = (int) ceil(($needed / $restoreRate) * 1000);

            return min($waitMs, $maxWait);
        }

        return min(1000, $maxWait);
    }

    /**
     * Reserve estimated cost from the local Storefront bucket before sending.
     * One in-process wait + one retry; any remaining deficit is accepted and
     * left to the THROTTLED retry path (which itself bubbles to queue backoff()).
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
            $this->budget->tryAcquire($shopDomain, $estimated, $max, $rate);
        }
    }

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
            (int) ($status['currentlyAvailable'] ?? 0),
        );
    }
}
