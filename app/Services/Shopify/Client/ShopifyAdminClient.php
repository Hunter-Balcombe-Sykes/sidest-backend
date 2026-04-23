<?php

namespace App\Services\Shopify\Client;

use App\Exceptions\Shopify\ShopifyGraphQLException;
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
        $queryHash = sha1($query);

        $response = $this->post($shopDomain, $accessToken, $apiVersion, $query, $variables, $timeout);

        $this->handleGraphqlErrors($response, $shopDomain, $queryHash);

        return $response;
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

        // Throttled errors are handled in the retry loop (added in Task 8). For now,
        // any top-level errors → ShopifyGraphQLException.
        $this->metrics->graphqlError($shopDomain, $queryHash, $errors);
        throw new ShopifyGraphQLException($shopDomain, $errors, $queryHash);
    }
}
