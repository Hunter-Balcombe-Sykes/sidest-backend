<?php

namespace App\Services\Shopify\Client;

use App\Exceptions\Shopify\ShopifyGraphQLException;
use App\Exceptions\Shopify\ShopifyThrottledException;
use App\Exceptions\Shopify\ShopifyTransportException;
use App\Services\Shopify\ShopDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

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
     * @throws ShopifyGraphQLException top-level GraphQL errors (excluding THROTTLED)
     * @throws ShopifyTransportException non-2xx HTTP, timeout, or connection failure
     */
    public function graphql(
        ShopDomain $shop,
        string $accessToken,
        string $apiVersion,
        string $query,
        array $variables = [],
        ?int $timeoutSeconds = null,
    ): Response {
        $timeout = $timeoutSeconds ?? (int) config('services.shopify.throttle.default_timeout', 20);
        $queryHash = sha1($query);
        $shopDomain = $shop->value;

        // One immediate retry without sleep — absorbs single-packet transient
        // blips where the local bucket and Shopify's edge disagree about how
        // much capacity is available. Anything beyond that throws and lets
        // the job's backoff() handle the delay across workers, instead of
        // tying up a worker thread with usleep(). Master Pattern 17 / DB-D#SCALE-3.
        $maxImmediateRetries = 1;
        $attempt = 0;

        while (true) {
            $this->preAcquireBudget($shopDomain, $queryHash);
            $started = microtime(true);
            $response = $this->post($shop, $accessToken, $apiVersion, $query, $variables, $timeout);
            $this->reconcileFromResponse($response, $shopDomain, $queryHash, $started);

            if ($this->isThrottled($response)) {
                $maxWait = (int) config('services.shopify.throttle.max_wait_ms', 5000);
                $wait = $this->throttleWaitMs($response, $maxWait);
                if ($attempt >= $maxImmediateRetries) {
                    throw new ShopifyThrottledException($shopDomain, $wait, $attempt, $queryHash);
                }
                // No usleep — bubble retry-delay to the queue's backoff() on
                // the throw path. The second attempt re-pre-acquires (which
                // itself does one short usleep on deficit), so we don't fire
                // back-to-back-to-back at Shopify's edge. The wait we'd have
                // slept is still recorded for observability.
                $this->metrics->throttled($shopDomain, $wait, $attempt + 1);
                $attempt++;

                continue;
            }

            $this->handleGraphqlErrors($response, $shopDomain, $queryHash);

            return $response;
        }
    }

    /**
     * Execute a REST request against the Shopify Admin API.
     *
     * REST throttling is bucket-based (40 calls on standard, 80 on Plus) and
     * signalled via HTTP 429 + Retry-After. Low-volume in our codebase so we
     * only react to 429 rather than pre-throttling.
     *
     * @param  string  $method  'GET'|'POST'|'PUT'|'DELETE'
     * @param  string  $path  must start with `/admin/...`
     * @param  array<string, mixed>  $body
     * @param  bool  $allow401  pass true for token-revoke calls where 401 means "already revoked"
     *
     * @throws ShopifyTransportException on non-2xx (excluding 401 when $allow401 is true)
     */
    public function rest(
        string $method,
        ShopDomain $shop,
        string $accessToken,
        string $path,
        array $body = [],
        ?int $timeoutSeconds = null,
        bool $allow401 = false,
    ): Response {
        // Defence in depth: refuse any path that doesn't address the Admin API.
        // Without this guard, a buggy caller could request e.g. `/oauth/...` on
        // the shop domain — outside the API surface this client is supposed to
        // speak to — and the access token would still be sent.
        if (! str_starts_with($path, '/admin/')) {
            throw new InvalidArgumentException(
                "ShopifyAdminClient::rest() requires a path under /admin/, got: {$path}"
            );
        }

        $timeout = $timeoutSeconds ?? (int) config('services.shopify.throttle.default_timeout', 20);
        $maxRetries = (int) config('services.shopify.throttle.max_inprocess_retries', 3);
        $shopDomain = $shop->value;
        $url = "https://{$shopDomain}{$path}";

        $attempt = 0;
        while (true) {
            try {
                $pending = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])->timeout($timeout);
                $response = match (strtoupper($method)) {
                    'GET' => $pending->get($url, $body),
                    'POST' => $pending->post($url, $body),
                    'PUT' => $pending->put($url, $body),
                    'DELETE' => $pending->delete($url, $body),
                    default => throw new InvalidArgumentException("Unsupported HTTP method: {$method}"),
                };
            } catch (ConnectionException $e) {
                throw new ShopifyTransportException($shopDomain, 0, $e->getMessage(), $e);
            }

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
                $this->metrics->throttled($shopDomain, $wait, $attempt + 1);
                // Blocks the worker thread — keep max_inprocess_retries low (default 3).
                usleep($wait * 1000);
                $attempt++;

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            if ($allow401 && $response->status() === 401) {
                return $response;
            }

            throw new ShopifyTransportException($shopDomain, $response->status(), (string) $response->body());
        }
    }

    /**
     * Start a `bulkOperationRunQuery`. Returns the operation GID.
     *
     * Acquires the per-shop bulk lock; only one bulk op may be in flight at a time.
     * Caller is responsible for calling `waitForBulkOperation()` which releases
     * the lock on terminal state.
     */
    public function bulkQuery(
        ShopDomain $shop,
        string $accessToken,
        string $apiVersion,
        string $query,
    ): string {
        $shopDomain = $shop->value;

        if (! $this->bulkLock->acquire($shopDomain)) {
            $this->metrics->bulkLockContended($shopDomain);
            throw new \RuntimeException("Shopify bulk operation already in progress for {$shopDomain}");
        }

        $mutation = <<<'GRAPHQL'
        mutation bulkOperationRunQuery($query: String!) {
          bulkOperationRunQuery(query: $query) {
            bulkOperation { id status }
            userErrors { field message }
          }
        }
        GRAPHQL;

        try {
            $response = $this->graphql($shop, $accessToken, $apiVersion, $mutation, ['query' => $query]);
        } catch (\Throwable $e) {
            $this->bulkLock->release($shopDomain);
            throw $e;
        }

        $userErrors = $response->json('data.bulkOperationRunQuery.userErrors', []);

        if (! empty($userErrors)) {
            $this->bulkLock->release($shopDomain);
            throw new \RuntimeException('Shopify bulkOperationRunQuery userErrors: '.json_encode($userErrors));
        }

        return (string) $response->json('data.bulkOperationRunQuery.bulkOperation.id');
    }

    /**
     * Start a `bulkOperationRunMutation`. Returns the operation GID.
     * Same locking semantics as bulkQuery.
     */
    public function bulkMutation(
        ShopDomain $shop,
        string $accessToken,
        string $apiVersion,
        string $mutation,
        string $stagedUploadPath,
    ): string {
        $shopDomain = $shop->value;

        if (! $this->bulkLock->acquire($shopDomain)) {
            $this->metrics->bulkLockContended($shopDomain);
            throw new \RuntimeException("Shopify bulk operation already in progress for {$shopDomain}");
        }

        $runner = <<<'GRAPHQL'
        mutation bulkOperationRunMutation($mutation: String!, $stagedUploadPath: String!) {
          bulkOperationRunMutation(mutation: $mutation, stagedUploadPath: $stagedUploadPath) {
            bulkOperation { id status }
            userErrors { field message }
          }
        }
        GRAPHQL;

        try {
            $response = $this->graphql($shop, $accessToken, $apiVersion, $runner, [
                'mutation' => $mutation,
                'stagedUploadPath' => $stagedUploadPath,
            ]);
        } catch (\Throwable $e) {
            $this->bulkLock->release($shopDomain);
            throw $e;
        }

        $userErrors = $response->json('data.bulkOperationRunMutation.userErrors', []);

        if (! empty($userErrors)) {
            $this->bulkLock->release($shopDomain);
            throw new \RuntimeException('Shopify bulkOperationRunMutation userErrors: '.json_encode($userErrors));
        }

        return (string) $response->json('data.bulkOperationRunMutation.bulkOperation.id');
    }

    /**
     * Poll a bulk operation until it reaches a terminal state.
     * Releases the per-shop bulk lock on terminal state.
     *
     * WARNING: synchronous — blocks the Horizon worker for up to $timeoutSeconds.
     * Run in a dedicated long-running job, not an inline service call.
     *
     * @return array{status: string, url: string|null, error_code: string|null}
     */
    public function waitForBulkOperation(
        ShopDomain $shop,
        string $accessToken,
        string $apiVersion,
        string $operationId,
        int $pollIntervalMs = 2000,
        int $timeoutSeconds = 600,
    ): array {
        $shopDomain = $shop->value;

        $query = <<<'GRAPHQL'
        query bulkOperationStatus($id: ID!) {
          node(id: $id) { ... on BulkOperation { id status errorCode url } }
        }
        GRAPHQL;

        $deadline = microtime(true) + $timeoutSeconds;
        $terminal = ['COMPLETED', 'FAILED', 'CANCELED', 'EXPIRED'];

        while (microtime(true) < $deadline) {
            try {
                $response = $this->graphql($shop, $accessToken, $apiVersion, $query, ['id' => $operationId]);
            } catch (\Throwable $e) {
                $this->bulkLock->release($shopDomain);
                throw $e;
            }
            $node = $response->json('data.node', []);
            $status = (string) ($node['status'] ?? 'UNKNOWN');

            if (in_array($status, $terminal, true)) {
                $this->bulkLock->release($shopDomain);

                return [
                    'status' => $status,
                    'url' => $node['url'] ?? null,
                    'error_code' => $node['errorCode'] ?? null,
                ];
            }

            usleep($pollIntervalMs * 1000);
        }

        // Timed out before terminal — release lock so shop isn't stuck
        $this->bulkLock->release($shopDomain);
        throw new \RuntimeException("Shopify bulk operation {$operationId} on {$shopDomain} timed out after {$timeoutSeconds}s");
    }

    private function post(
        ShopDomain $shop,
        string $accessToken,
        string $apiVersion,
        string $query,
        array $variables,
        int $timeout,
    ): Response {
        $shopDomain = $shop->value;

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
