# Shopify Admin API Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 20 direct `Http::withHeaders(...)->post(...)` Shopify Admin API call sites with a single cost-aware `ShopifyAdminClient` that honours Shopify's GraphQL leaky-bucket throttle model, reconciles with Shopify's authoritative bucket state after every response, tracks cost estimates per query, and surfaces typed exceptions so Horizon's existing `backoff()` retry handles overflow.

**Architecture:** A single `ShopifyAdminClient` service encapsulates all Shopify Admin API calls. It delegates to three collaborators: `ShopifyBudgetTracker` (atomic Redis Lua token-bucket per `shop_domain`), `ShopifyCostTracker` (rolling actual/requested cost ratio per query hash), and `ShopifyMetrics` (observability hooks via Log + Nightwatch). A `ShopifyBulkOperationLock` provides a per-shop mutex for `bulkOperationRunMutation`. Throttled GraphQL responses (HTTP 200 with `errors[].extensions.code === "THROTTLED"`) and REST 429 responses are handled with in-process retries up to a bounded limit; beyond that, a typed `ShopifyThrottledException` bubbles up and the queue's existing `tries=3` + exponential backoff takes over.

**Tech Stack:** Laravel 12, PHP 8.2, Redis (default DB 0 — Laravel's `cache` connection), Pest 4 + PHPUnit, Mockery, `Illuminate\Http\Client\Factory` (for `Http::fake()` in tests).

**Key design calls:**
- **Atomic token bucket** — Redis Lua script (invoked via `Redis::command('eval', ...)`) from day one to avoid concurrency races when multiple workers hit the same shop.
- **Reconcile > estimate** — after every successful response, overwrite the local bucket with Shopify's reported `throttleStatus.currentlyAvailable`. Local state is just a safety net for burst starts before the first response lands.
- **Per-query cost learning** — record `requestedQueryCost` / `actualQueryCost` per query hash. Use the ratio to pre-reserve conservatively.
- **REST is low-volume** — only one REST call exists today (teardown token revoke). Don't pre-throttle REST; only handle 429 + `Retry-After` reactively.
- **Bulk ops are additive** — scaffold the helpers now so the first big import doesn't force a client rewrite, but don't migrate existing call sites to bulk (none of them need it yet).
- **Response object passthrough** — the client returns `Illuminate\Http\Client\Response` (matching existing call-site expectations) so migrations are minimally invasive. Throttle handling is hidden inside the client.

---

## File Structure

### Created (20)

| Path | Responsibility |
|------|----------------|
| `app/Exceptions/Shopify/ShopifyClientException.php` | Base class for all Shopify client errors |
| `app/Exceptions/Shopify/ShopifyThrottledException.php` | Throttle budget exhausted after in-process retries |
| `app/Exceptions/Shopify/ShopifyGraphQLException.php` | Top-level GraphQL `errors[]` other than THROTTLED |
| `app/Exceptions/Shopify/ShopifyTransportException.php` | Non-2xx HTTP, network failure, timeout |
| `app/Services/Shopify/Client/ShopifyAdminClient.php` | Main facade — `graphql()`, `rest()`, `bulkQuery()`, `bulkMutation()`, `waitForBulkOperation()` |
| `app/Services/Shopify/Client/ShopifyBudgetTracker.php` | Redis Lua token-bucket — `tryAcquire()`, `reconcile()` |
| `app/Services/Shopify/Client/ShopifyCostTracker.php` | Per-query rolling actual/requested cost ratio |
| `app/Services/Shopify/Client/ShopifyMetrics.php` | Observability hooks (counter + log + Nightwatch tag) |
| `app/Services/Shopify/Client/ShopifyBulkOperationLock.php` | Per-shop Redis SETNX mutex for bulk ops |
| `tests/Unit/Services/Shopify/Client/ShopifyBudgetTrackerTest.php` | Lua atomicity, refill, reconcile tests |
| `tests/Unit/Services/Shopify/Client/ShopifyCostTrackerTest.php` | Cost ratio estimation tests |
| `tests/Unit/Services/Shopify/Client/ShopifyBulkOperationLockTest.php` | Acquire/release/contention tests |
| `tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php` | Happy path, THROTTLED retry, exhaustion, reconcile |
| `tests/Feature/Services/Shopify/Client/ShopifyAdminClientRestTest.php` | REST 429 + Retry-After handling |
| `tests/Feature/Services/Shopify/Client/ShopifyAdminClientBulkTest.php` | Bulk query/mutation + polling |

### Modified

| Path | Change |
|------|--------|
| `config/services.php` | Add `shopify.throttle` block (defaults, timeouts, max in-process retries) |
| `app/Providers/AppServiceProvider.php` | Bind `ShopifyAdminClient` + collaborators as singletons |
| `app/Services/Shopify/ShopifyTeardownService.php` | Replace inline `graphql()` helper + `revokeOauthToken()` with client calls |
| `app/Services/Shopify/BrandDesignImporter.php` | Replace 3 direct `Http::` calls with client |
| `app/Services/Shopify/ShopifyDataResyncService.php` | Replace 1 direct `Http::` call with client |
| `app/Jobs/Shopify/CreateShopifyMetafieldsJob.php` | Replace inline `graphql()` helper with client |
| `app/Jobs/Shopify/CreateShopifyCollectionsJob.php` | Replace inline `graphql()` helper with client |
| `app/Jobs/Shopify/CreateShopifySalesChannelJob.php` | Replace direct `Http::` calls with client |
| `app/Jobs/Shopify/CreateStorefrontAccessTokenJob.php` | Replace direct `Http::` calls with client |
| `app/Jobs/Shopify/SetShopifySetupCompleteJob.php` | Replace direct `Http::` calls with client |
| `app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php` | Replace direct `Http::` calls with client |
| `app/Jobs/Shopify/RegisterShopifyWebhooksJob.php` | Replace direct `Http::` call with client |
| `app/Jobs/Shopify/SyncShopifyBrandDesignJob.php` | Replace direct `Http::` calls with client |
| `app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php` | Replace direct `Http::` calls with client |

---

## Task 1: Configuration + typed exception hierarchy

**Files:**
- Modify: `config/services.php`
- Create: `app/Exceptions/Shopify/ShopifyClientException.php`
- Create: `app/Exceptions/Shopify/ShopifyThrottledException.php`
- Create: `app/Exceptions/Shopify/ShopifyGraphQLException.php`
- Create: `app/Exceptions/Shopify/ShopifyTransportException.php`

- [ ] **Step 1: Add throttle config block to `config/services.php`**

Edit `config/services.php` — inside the existing `'shopify' => [...]` array (line 72-85), append a `'throttle'` key after `'app_handle'`:

```php
    'shopify' => [
        // ... existing keys ...
        'app_handle' => env('SHOPIFY_APP_HANDLE', 'side-st-hydrogen'),

        // Admin API throttle client config. Shopify standard-plan GraphQL
        // bucket is 1000 points, restoring at 100 pts/sec. Plus is 2000/200.
        // We learn the actual values from throttleStatus on every response.
        'throttle' => [
            'default_max_capacity' => (int) env('SHOPIFY_THROTTLE_MAX', 1000),
            'default_restore_rate' => (int) env('SHOPIFY_THROTTLE_RESTORE_RATE', 100),
            'default_estimated_cost' => (int) env('SHOPIFY_THROTTLE_DEFAULT_COST', 10),
            'max_inprocess_retries' => (int) env('SHOPIFY_THROTTLE_MAX_RETRIES', 3),
            'max_wait_ms' => (int) env('SHOPIFY_THROTTLE_MAX_WAIT_MS', 5000),
            'default_timeout' => (int) env('SHOPIFY_HTTP_TIMEOUT', 20),
            'bucket_ttl_seconds' => 60,
            'cost_window_size' => 20,
            'bulk_lock_ttl_seconds' => 3600,
        ],
    ],
```

- [ ] **Step 2: Create the base exception class**

Create `app/Exceptions/Shopify/ShopifyClientException.php`:

```php
<?php

namespace App\Exceptions\Shopify;

use RuntimeException;

abstract class ShopifyClientException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $shopDomain,
        public readonly ?string $queryHash = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

- [ ] **Step 3: Create `ShopifyThrottledException`**

Create `app/Exceptions/Shopify/ShopifyThrottledException.php`:

```php
<?php

namespace App\Exceptions\Shopify;

class ShopifyThrottledException extends ShopifyClientException
{
    public function __construct(
        string $shopDomain,
        public readonly int $waitMs,
        public readonly int $attempts,
        ?string $queryHash = null,
    ) {
        parent::__construct(
            "Shopify throttled {$shopDomain} after {$attempts} in-process retries (needed {$waitMs}ms wait)",
            $shopDomain,
            $queryHash,
        );
    }
}
```

- [ ] **Step 4: Create `ShopifyGraphQLException`**

Create `app/Exceptions/Shopify/ShopifyGraphQLException.php`:

```php
<?php

namespace App\Exceptions\Shopify;

class ShopifyGraphQLException extends ShopifyClientException
{
    /**
     * @param  array<int, array<string, mixed>>  $graphqlErrors
     */
    public function __construct(
        string $shopDomain,
        public readonly array $graphqlErrors,
        ?string $queryHash = null,
    ) {
        $first = $graphqlErrors[0]['message'] ?? 'unknown';
        parent::__construct(
            "Shopify GraphQL error on {$shopDomain}: {$first}",
            $shopDomain,
            $queryHash,
        );
    }
}
```

- [ ] **Step 5: Create `ShopifyTransportException`**

Create `app/Exceptions/Shopify/ShopifyTransportException.php`:

```php
<?php

namespace App\Exceptions\Shopify;

class ShopifyTransportException extends ShopifyClientException
{
    public function __construct(
        string $shopDomain,
        public readonly int $status,
        public readonly string $body,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Shopify transport error on {$shopDomain} (HTTP {$status})",
            $shopDomain,
            null,
            $previous,
        );
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add config/services.php app/Exceptions/Shopify/
git commit -m "feat(shopify): add throttle config + typed client exception hierarchy"
```

---

## Task 2: ShopifyMetrics abstraction

**Files:**
- Create: `app/Services/Shopify/Client/ShopifyMetrics.php`

- [ ] **Step 1: Create the metrics class**

Create `app/Services/Shopify/Client/ShopifyMetrics.php`:

```php
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

    public function graphqlError(string $shopDomain, string $queryHash, array $errors): void
    {
        Log::error('shopify.client.graphql_error', [
            'shop_domain' => $shopDomain,
            'query_hash' => $queryHash,
            'errors' => $errors,
        ]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyMetrics.php
git commit -m "feat(shopify): add ShopifyMetrics for client observability"
```

---

## Task 3: ShopifyBudgetTracker — atomic token bucket (Redis Lua)

**Files:**
- Create: `app/Services/Shopify/Client/ShopifyBudgetTracker.php`
- Test: `tests/Unit/Services/Shopify/Client/ShopifyBudgetTrackerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Shopify/Client/ShopifyBudgetTrackerTest.php`:

```php
<?php

use App\Services\Shopify\Client\ShopifyBudgetTracker;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->tracker = new ShopifyBudgetTracker();
    $this->shop = 'test-shop.myshopify.com';
});

it('grants full capacity on first acquire', function () {
    $result = $this->tracker->tryAcquire($this->shop, estimatedCost: 100, maxCapacity: 1000, restoreRate: 100);

    expect($result['acquired'])->toBeTrue()
        ->and($result['wait_ms'])->toBe(0)
        ->and($result['remaining'])->toBe(900);
});

it('refuses acquire when cost exceeds current tokens and returns wait time', function () {
    $this->tracker->tryAcquire($this->shop, estimatedCost: 950, maxCapacity: 1000, restoreRate: 100);

    $result = $this->tracker->tryAcquire($this->shop, estimatedCost: 500, maxCapacity: 1000, restoreRate: 100);

    expect($result['acquired'])->toBeFalse()
        ->and($result['wait_ms'])->toBeGreaterThan(0)
        ->and($result['wait_ms'])->toBeLessThanOrEqual(5000);
});

it('refills over time at the restore rate', function () {
    $this->tracker->tryAcquire($this->shop, estimatedCost: 900, maxCapacity: 1000, restoreRate: 100);

    // Simulate 2 seconds elapsed by manually rewinding updated_ms
    $pastMs = (int) (microtime(true) * 1000) - 2000;
    Redis::hset("shopify:bucket:{$this->shop}", 'updated_ms', $pastMs);

    $result = $this->tracker->tryAcquire($this->shop, estimatedCost: 100, maxCapacity: 1000, restoreRate: 100);

    // Started at 100, +200 refill = 300, minus 100 cost = 200 remaining
    expect($result['acquired'])->toBeTrue()
        ->and($result['remaining'])->toBeGreaterThanOrEqual(190)
        ->and($result['remaining'])->toBeLessThanOrEqual(210);
});

it('caps refill at max capacity', function () {
    $this->tracker->tryAcquire($this->shop, estimatedCost: 500, maxCapacity: 1000, restoreRate: 100);

    // Simulate 1 hour elapsed
    $pastMs = (int) (microtime(true) * 1000) - 3_600_000;
    Redis::hset("shopify:bucket:{$this->shop}", 'updated_ms', $pastMs);

    $result = $this->tracker->tryAcquire($this->shop, estimatedCost: 100, maxCapacity: 1000, restoreRate: 100);

    expect($result['remaining'])->toBe(900);
});

it('reconcile overwrites local state with authoritative Shopify value', function () {
    $this->tracker->tryAcquire($this->shop, estimatedCost: 100, maxCapacity: 1000, restoreRate: 100);

    $this->tracker->reconcile($this->shop, currentlyAvailable: 750, maximumAvailable: 1000, restoreRate: 100);

    $next = $this->tracker->tryAcquire($this->shop, estimatedCost: 50, maxCapacity: 1000, restoreRate: 100);
    expect($next['remaining'])->toBe(700);
});

it('is atomic under concurrent acquires', function () {
    // Fire 20 concurrent 100-point acquires against a 1000-point bucket.
    // Exactly 10 should succeed (1000 / 100), not 11 (race would leak).
    $results = [];
    for ($i = 0; $i < 20; $i++) {
        $results[] = $this->tracker->tryAcquire($this->shop, 100, 1000, 100)['acquired'];
    }

    $successes = count(array_filter($results));
    expect($successes)->toBe(10);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShopifyBudgetTrackerTest`
Expected: FAIL with "Class ShopifyBudgetTracker not found"

- [ ] **Step 3: Create the implementation**

Create `app/Services/Shopify/Client/ShopifyBudgetTracker.php`:

```php
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
        $result = Redis::command('eval', [
            self::LUA_ACQUIRE,
            1,
            $this->key($shopDomain),
            $maxCapacity,
            $restoreRate,
            (int) (microtime(true) * 1000),
            $estimatedCost,
            $ttl,
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
        Redis::command('eval', [
            self::LUA_RECONCILE,
            1,
            $this->key($shopDomain),
            $currentlyAvailable,
            (int) (microtime(true) * 1000),
            $ttl,
        ]);
    }

    private function key(string $shopDomain): string
    {
        return "shopify:bucket:{$shopDomain}";
    }
}
```

- [ ] **Step 4: Run test to verify all pass**

Run: `php artisan test --filter=ShopifyBudgetTrackerTest`
Expected: All 6 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyBudgetTracker.php tests/Unit/Services/Shopify/Client/ShopifyBudgetTrackerTest.php
git commit -m "feat(shopify): add ShopifyBudgetTracker with atomic Redis Lua token bucket"
```

---

## Task 4: ShopifyCostTracker — rolling per-query cost ratio

**Files:**
- Create: `app/Services/Shopify/Client/ShopifyCostTracker.php`
- Test: `tests/Unit/Services/Shopify/Client/ShopifyCostTrackerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Shopify/Client/ShopifyCostTrackerTest.php`:

```php
<?php

use App\Services\Shopify\Client\ShopifyCostTracker;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->tracker = new ShopifyCostTracker();
});

it('returns a conservative default estimate when nothing is recorded', function () {
    $estimate = $this->tracker->estimate('some-query-hash', requestedCost: 100);

    // No history yet — use requestedCost as-is (Shopify's estimate)
    expect($estimate)->toBe(100);
});

it('records actual cost and adjusts future estimates downward if actual < requested', function () {
    // Shopify typically charges much less than requestedCost for list queries.
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->record('q1', requestedCost: 100, actualCost: 20);
    }

    // Ratio is 0.2, so new estimate for same query should be 100 * 0.2 = 20
    $estimate = $this->tracker->estimate('q1', requestedCost: 100);
    expect($estimate)->toBe(20);
});

it('never returns an estimate lower than the minimum floor', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->record('q1', requestedCost: 100, actualCost: 1);
    }

    // Ratio would push estimate to 1, but we enforce a minimum of 10
    $estimate = $this->tracker->estimate('q1', requestedCost: 100);
    expect($estimate)->toBeGreaterThanOrEqual(10);
});

it('uses a bounded sliding window so stale data ages out', function () {
    // Window size is 20 — fill with low-cost samples, then flip to high-cost
    for ($i = 0; $i < 20; $i++) {
        $this->tracker->record('q1', 100, 20);
    }
    expect($this->tracker->estimate('q1', 100))->toBe(20);

    // Now 20 new high-cost samples — old should age out
    for ($i = 0; $i < 20; $i++) {
        $this->tracker->record('q1', 100, 80);
    }
    expect($this->tracker->estimate('q1', 100))->toBeGreaterThanOrEqual(75);
});

it('keeps separate history per query hash', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->record('q1', 100, 20);
        $this->tracker->record('q2', 100, 80);
    }

    expect($this->tracker->estimate('q1', 100))->toBe(20);
    expect($this->tracker->estimate('q2', 100))->toBe(80);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShopifyCostTrackerTest`
Expected: FAIL with "Class ShopifyCostTracker not found"

- [ ] **Step 3: Create the implementation**

Create `app/Services/Shopify/Client/ShopifyCostTracker.php`:

```php
<?php

namespace App\Services\Shopify\Client;

use Illuminate\Support\Facades\Redis;

/**
 * Rolling-window tracker for the ratio of actual to requested GraphQL cost,
 * keyed per query hash (sha1 of the query string).
 *
 * Shopify's `requestedQueryCost` is a pre-execution estimate. For list queries
 * with `first: N`, the actual charge can be 5-20x lower. Learning the ratio
 * per query lets us pre-reserve conservatively without being paranoid.
 */
class ShopifyCostTracker
{
    private const MIN_ESTIMATE = 10;

    public function record(string $queryHash, int $requestedCost, int $actualCost): void
    {
        if ($requestedCost <= 0) {
            return;
        }

        $windowSize = (int) config('services.shopify.throttle.cost_window_size', 20);
        $key = $this->key($queryHash);

        Redis::pipeline(function ($pipe) use ($key, $requestedCost, $actualCost, $windowSize) {
            $pipe->lpush($key, "{$requestedCost}:{$actualCost}");
            $pipe->ltrim($key, 0, $windowSize - 1);
            $pipe->expire($key, 86400);
        });
    }

    public function estimate(string $queryHash, int $requestedCost): int
    {
        $samples = Redis::lrange($this->key($queryHash), 0, -1);
        if (empty($samples)) {
            return max(self::MIN_ESTIMATE, $requestedCost);
        }

        $totalRequested = 0;
        $totalActual = 0;
        foreach ($samples as $sample) {
            [$req, $act] = explode(':', $sample);
            $totalRequested += (int) $req;
            $totalActual += (int) $act;
        }

        if ($totalRequested === 0) {
            return max(self::MIN_ESTIMATE, $requestedCost);
        }

        $ratio = $totalActual / $totalRequested;
        $estimate = (int) ceil($requestedCost * $ratio);

        return max(self::MIN_ESTIMATE, $estimate);
    }

    private function key(string $queryHash): string
    {
        return "shopify:cost:{$queryHash}";
    }
}
```

- [ ] **Step 4: Run test to verify all pass**

Run: `php artisan test --filter=ShopifyCostTrackerTest`
Expected: All 5 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyCostTracker.php tests/Unit/Services/Shopify/Client/ShopifyCostTrackerTest.php
git commit -m "feat(shopify): add ShopifyCostTracker for per-query cost learning"
```

---

## Task 5: ShopifyBulkOperationLock — per-shop mutex

**Files:**
- Create: `app/Services/Shopify/Client/ShopifyBulkOperationLock.php`
- Test: `tests/Unit/Services/Shopify/Client/ShopifyBulkOperationLockTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Shopify/Client/ShopifyBulkOperationLockTest.php`:

```php
<?php

use App\Services\Shopify\Client\ShopifyBulkOperationLock;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->lock = new ShopifyBulkOperationLock();
    $this->shop = 'test-shop.myshopify.com';
});

it('acquires the lock on first try', function () {
    expect($this->lock->acquire($this->shop))->toBeTrue();
});

it('refuses a second acquire while the first is held', function () {
    $this->lock->acquire($this->shop);

    expect($this->lock->acquire($this->shop))->toBeFalse();
});

it('allows re-acquire after release', function () {
    $this->lock->acquire($this->shop);
    $this->lock->release($this->shop);

    expect($this->lock->acquire($this->shop))->toBeTrue();
});

it('scopes locks per shop', function () {
    $this->lock->acquire('shop-a.myshopify.com');

    expect($this->lock->acquire('shop-b.myshopify.com'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShopifyBulkOperationLockTest`
Expected: FAIL with "Class ShopifyBulkOperationLock not found"

- [ ] **Step 3: Create the implementation**

Create `app/Services/Shopify/Client/ShopifyBulkOperationLock.php`:

```php
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
```

- [ ] **Step 4: Run test to verify all pass**

Run: `php artisan test --filter=ShopifyBulkOperationLockTest`
Expected: All 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyBulkOperationLock.php tests/Unit/Services/Shopify/Client/ShopifyBulkOperationLockTest.php
git commit -m "feat(shopify): add ShopifyBulkOperationLock per-shop mutex"
```

---

## Task 6: ShopifyAdminClient — GraphQL core with typed errors (no budget yet)

**Files:**
- Create: `app/Services/Shopify/Client/ShopifyAdminClient.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php`

- [ ] **Step 1: Write the failing test (happy path + error cases, no throttle yet)**

Create `tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php`:

```php
<?php

use App\Exceptions\Shopify\ShopifyGraphQLException;
use App\Exceptions\Shopify\ShopifyTransportException;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->client = app(ShopifyAdminClient::class);
    $this->shop = 'test.myshopify.com';
    $this->token = 'shpat_test';
    $this->version = '2025-01';
});

it('returns the Response object on a successful GraphQL call', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['shop' => ['id' => 'gid://shopify/Shop/1']],
            'extensions' => [
                'cost' => [
                    'requestedQueryCost' => 1,
                    'actualQueryCost' => 1,
                    'throttleStatus' => [
                        'maximumAvailable' => 1000,
                        'currentlyAvailable' => 999,
                        'restoreRate' => 100,
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }');

    expect($response->json('data.shop.id'))->toBe('gid://shopify/Shop/1');
});

it('throws ShopifyGraphQLException when top-level errors are present (non-throttled)', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'errors' => [
                ['message' => 'Field "foo" does not exist', 'extensions' => ['code' => 'undefinedField']],
            ],
        ], 200),
    ]);

    expect(fn () => $this->client->graphql($this->shop, $this->token, $this->version, 'query { foo }'))
        ->toThrow(ShopifyGraphQLException::class);
});

it('throws ShopifyTransportException on non-2xx HTTP status', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response('Internal Server Error', 500),
    ]);

    expect(fn () => $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }'))
        ->toThrow(ShopifyTransportException::class);
});

it('sends the access token in the X-Shopify-Access-Token header', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['ok' => true],
            'extensions' => ['cost' => ['requestedQueryCost' => 1, 'actualQueryCost' => 1, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 999, 'restoreRate' => 100]]],
        ], 200),
    ]);

    $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }');

    Http::assertSent(function ($request) {
        return $request->header('X-Shopify-Access-Token')[0] === 'shpat_test';
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShopifyAdminClientGraphqlTest`
Expected: FAIL with "Class ShopifyAdminClient not found"

- [ ] **Step 3: Create the initial implementation (graphql only, no throttle yet)**

Create `app/Services/Shopify/Client/ShopifyAdminClient.php`:

```php
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

        // Throttled errors are handled elsewhere (added in Task 8). For now,
        // any top-level errors → ShopifyGraphQLException.
        $this->metrics->graphqlError($shopDomain, $queryHash, $errors);
        throw new ShopifyGraphQLException($shopDomain, $errors, $queryHash);
    }
}
```

- [ ] **Step 4: Bind the client as a singleton**

Edit `app/Providers/AppServiceProvider.php` — add inside the `register()` method:

```php
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyAdminClient::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyBudgetTracker::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyCostTracker::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyMetrics::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyBulkOperationLock::class);
```

- [ ] **Step 5: Run tests to verify all 4 pass**

Run: `php artisan test --filter=ShopifyAdminClientGraphqlTest`
Expected: All 4 tests PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyAdminClient.php app/Providers/AppServiceProvider.php tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php
git commit -m "feat(shopify): add ShopifyAdminClient GraphQL core with typed errors"
```

---

## Task 7: ShopifyAdminClient — budget-aware pre-acquire + response reconcile

**Files:**
- Modify: `app/Services/Shopify/Client/ShopifyAdminClient.php`
- Test: `tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php` (add cases)

- [ ] **Step 1: Add failing tests for budget behaviour**

Append to `tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php`:

```php
it('reconciles the local bucket from throttleStatus after each response', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['ok' => true],
            'extensions' => [
                'cost' => [
                    'requestedQueryCost' => 100,
                    'actualQueryCost' => 20,
                    'throttleStatus' => [
                        'maximumAvailable' => 1000,
                        'currentlyAvailable' => 450,
                        'restoreRate' => 100,
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }');

    // After reconcile, local bucket should report 450 available.
    $tracker = app(\App\Services\Shopify\Client\ShopifyBudgetTracker::class);
    $result = $tracker->tryAcquire($this->shop, 50, 1000, 100);
    expect($result['remaining'])->toBe(400);
});

it('records actual cost for future query estimates', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['ok' => true],
            'extensions' => [
                'cost' => [
                    'requestedQueryCost' => 100,
                    'actualQueryCost' => 15,
                    'throttleStatus' => [
                        'maximumAvailable' => 1000,
                        'currentlyAvailable' => 985,
                        'restoreRate' => 100,
                    ],
                ],
            ],
        ], 200),
    ]);

    $query = 'query test { ok }';
    for ($i = 0; $i < 5; $i++) {
        $this->client->graphql($this->shop, $this->token, $this->version, $query);
    }

    $costTracker = app(\App\Services\Shopify\Client\ShopifyCostTracker::class);
    expect($costTracker->estimate(sha1($query), 100))->toBeLessThanOrEqual(20);
});
```

- [ ] **Step 2: Run tests to see them fail**

Run: `php artisan test --filter=ShopifyAdminClientGraphqlTest`
Expected: 2 new tests FAIL (local bucket not reconciled, cost not recorded)

- [ ] **Step 3: Add reconcile + cost recording to the client**

Edit `app/Services/Shopify/Client/ShopifyAdminClient.php` — replace the `graphql()` method with this version that pre-acquires and post-reconciles, and add the helper methods:

```php
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

        $this->preAcquireBudget($shopDomain, $queryHash);

        $started = microtime(true);
        $response = $this->post($shopDomain, $accessToken, $apiVersion, $query, $variables, $timeout);

        $this->handleGraphqlErrors($response, $shopDomain, $queryHash);
        $this->reconcileFromResponse($response, $shopDomain, $queryHash, $started);

        return $response;
    }

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
            // let the THROTTLED retry path handle it (added in Task 8).
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
        if (isset($status['currentlyAvailable'], $status['maximumAvailable'], $status['restoreRate'])) {
            $this->budget->reconcile(
                $shopDomain,
                (int) $status['currentlyAvailable'],
                (int) $status['maximumAvailable'],
                (int) $status['restoreRate'],
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
```

- [ ] **Step 4: Run all graphql tests to verify they pass**

Run: `php artisan test --filter=ShopifyAdminClientGraphqlTest`
Expected: All 6 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyAdminClient.php tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php
git commit -m "feat(shopify): pre-acquire budget and reconcile from throttleStatus"
```

---

## Task 8: ShopifyAdminClient — THROTTLED in-process retry

**Files:**
- Modify: `app/Services/Shopify/Client/ShopifyAdminClient.php`
- Test: `tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php` (add cases)

- [ ] **Step 1: Add failing tests for throttle retry + exhaustion**

Append to `tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php`:

```php
use App\Exceptions\Shopify\ShopifyThrottledException;

it('retries in-process on a THROTTLED response and succeeds when budget recovers', function () {
    $throttled = [
        'errors' => [
            ['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']],
        ],
        'extensions' => [
            'cost' => [
                'requestedQueryCost' => 100,
                'actualQueryCost' => 0,
                'throttleStatus' => [
                    'maximumAvailable' => 1000,
                    'currentlyAvailable' => 5,
                    'restoreRate' => 100,
                ],
            ],
        ],
    ];
    $success = [
        'data' => ['ok' => true],
        'extensions' => [
            'cost' => [
                'requestedQueryCost' => 100,
                'actualQueryCost' => 80,
                'throttleStatus' => [
                    'maximumAvailable' => 1000,
                    'currentlyAvailable' => 920,
                    'restoreRate' => 100,
                ],
            ],
        ],
    ];

    Http::fakeSequence("https://{$this->shop}/admin/api/{$this->version}/graphql.json")
        ->push($throttled, 200)
        ->push($success, 200);

    $response = $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }');

    expect($response->json('data.ok'))->toBeTrue();
});

it('throws ShopifyThrottledException when max in-process retries are exhausted', function () {
    config()->set('services.shopify.throttle.max_inprocess_retries', 2);

    $throttled = [
        'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
        'extensions' => ['cost' => ['requestedQueryCost' => 100, 'actualQueryCost' => 0, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 5, 'restoreRate' => 100]]],
    ];

    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response($throttled, 200),
    ]);

    expect(fn () => $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }'))
        ->toThrow(ShopifyThrottledException::class);
});
```

- [ ] **Step 2: Run tests to see them fail**

Run: `php artisan test --filter=ShopifyAdminClientGraphqlTest`
Expected: 2 new tests FAIL (no retry on THROTTLED; first test sees THROTTLED as a ShopifyGraphQLException)

- [ ] **Step 3: Add THROTTLED handling with in-process retry**

Edit `app/Services/Shopify/Client/ShopifyAdminClient.php` — replace the `graphql()` method body with the retry-wrapping version, and add `isThrottled()` and `throttleWaitMs()` private helpers. Also add `use App\Exceptions\Shopify\ShopifyThrottledException;` at the top.

```php
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

            // Always reconcile from response BEFORE throwing — even a THROTTLED
            // response carries a fresh throttleStatus we want to trust.
            $this->reconcileFromResponse($response, $shopDomain, $queryHash, $started);

            if ($this->isThrottled($response)) {
                if ($attempt >= $maxRetries) {
                    $wait = $this->throttleWaitMs($response, $maxWait);
                    throw new ShopifyThrottledException($shopDomain, $wait, $attempt, $queryHash);
                }

                $wait = $this->throttleWaitMs($response, $maxWait);
                $this->metrics->throttled($shopDomain, $wait, $attempt + 1);
                usleep($wait * 1000);
                $attempt++;
                continue;
            }

            $this->handleGraphqlErrors($response, $shopDomain, $queryHash);

            return $response;
        }
    }

    private function isThrottled(Response $response): bool
    {
        $errors = $response->json('errors', []);
        foreach ($errors as $err) {
            if (($err['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    private function throttleWaitMs(Response $response, int $maxWait): int
    {
        $cost = $response->json('extensions.cost', []);
        $requested = (int) ($cost['requestedQueryCost'] ?? 10);
        $available = (int) ($cost['throttleStatus']['currentlyAvailable'] ?? 0);
        $rate = (int) ($cost['throttleStatus']['restoreRate'] ?? 100);

        if ($available >= $requested || $rate <= 0) {
            return 500;
        }

        $deficit = $requested - $available;
        $wait = (int) ceil(($deficit / $rate) * 1000);

        return min(max($wait, 200), $maxWait);
    }
```

- [ ] **Step 4: Run all graphql tests to verify they pass**

Run: `php artisan test --filter=ShopifyAdminClientGraphqlTest`
Expected: All 8 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyAdminClient.php tests/Feature/Services/Shopify/Client/ShopifyAdminClientGraphqlTest.php
git commit -m "feat(shopify): handle THROTTLED responses with in-process retry"
```

---

## Task 9: ShopifyAdminClient — REST with 429 + Retry-After handling

**Files:**
- Modify: `app/Services/Shopify/Client/ShopifyAdminClient.php`
- Create: `tests/Feature/Services/Shopify/Client/ShopifyAdminClientRestTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Shopify/Client/ShopifyAdminClientRestTest.php`:

```php
<?php

use App\Exceptions\Shopify\ShopifyTransportException;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->client = app(ShopifyAdminClient::class);
    $this->shop = 'test.myshopify.com';
    $this->token = 'shpat_test';
    $this->version = '2025-01';
});

it('performs a successful DELETE', function () {
    Http::fake([
        "https://{$this->shop}/admin/api_permissions/current.json" => Http::response('', 204),
    ]);

    $response = $this->client->rest('DELETE', $this->shop, $this->token, "/admin/api_permissions/current.json");

    expect($response->status())->toBe(204);
});

it('retries once after a 429 with Retry-After and succeeds', function () {
    Http::fakeSequence("https://{$this->shop}/admin/api_permissions/current.json")
        ->push('', 429, ['Retry-After' => '1'])
        ->push('', 204);

    $response = $this->client->rest('DELETE', $this->shop, $this->token, "/admin/api_permissions/current.json");

    expect($response->status())->toBe(204);
});

it('treats 401 as successful for token-revoke semantics', function () {
    Http::fake([
        "https://{$this->shop}/admin/api_permissions/current.json" => Http::response('', 401),
    ]);

    $response = $this->client->rest('DELETE', $this->shop, $this->token, "/admin/api_permissions/current.json", allow401: true);

    expect($response->status())->toBe(401);
});

it('throws ShopifyTransportException on non-2xx after 429 retries exhausted', function () {
    config()->set('services.shopify.throttle.max_inprocess_retries', 1);

    Http::fake([
        "https://{$this->shop}/admin/api_permissions/current.json" => Http::response('', 429, ['Retry-After' => '1']),
    ]);

    expect(fn () => $this->client->rest('DELETE', $this->shop, $this->token, "/admin/api_permissions/current.json"))
        ->toThrow(ShopifyTransportException::class);
});
```

- [ ] **Step 2: Run tests to see them fail**

Run: `php artisan test --filter=ShopifyAdminClientRestTest`
Expected: All 4 tests FAIL (no `rest()` method on client)

- [ ] **Step 3: Add the `rest()` method to the client**

Edit `app/Services/Shopify/Client/ShopifyAdminClient.php` — append this public method below `graphql()`:

```php
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
     */
    public function rest(
        string $method,
        string $shopDomain,
        string $accessToken,
        string $path,
        array $body = [],
        ?int $timeoutSeconds = null,
        bool $allow401 = false,
    ): Response {
        $timeout = $timeoutSeconds ?? (int) config('services.shopify.throttle.default_timeout', 20);
        $maxRetries = (int) config('services.shopify.throttle.max_inprocess_retries', 3);
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
                    default => throw new \InvalidArgumentException("Unsupported method {$method}"),
                };
            } catch (ConnectionException $e) {
                throw new ShopifyTransportException($shopDomain, 0, $e->getMessage(), $e);
            }

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $wait = max(1, (int) $response->header('Retry-After')) * 1000;
                $this->metrics->throttled($shopDomain, $wait, $attempt + 1);
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
```

- [ ] **Step 4: Run REST tests to verify all pass**

Run: `php artisan test --filter=ShopifyAdminClientRestTest`
Expected: All 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyAdminClient.php tests/Feature/Services/Shopify/Client/ShopifyAdminClientRestTest.php
git commit -m "feat(shopify): add REST helper with 429 Retry-After handling"
```

---

## Task 10: ShopifyAdminClient — bulk operation helpers

**Files:**
- Modify: `app/Services/Shopify/Client/ShopifyAdminClient.php`
- Create: `tests/Feature/Services/Shopify/Client/ShopifyAdminClientBulkTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Shopify/Client/ShopifyAdminClientBulkTest.php`:

```php
<?php

use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\Client\ShopifyBulkOperationLock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->client = app(ShopifyAdminClient::class);
    $this->shop = 'test.myshopify.com';
    $this->token = 'shpat_test';
    $this->version = '2025-01';
});

it('starts a bulk query and returns the operation id', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => [
                'bulkOperationRunQuery' => [
                    'bulkOperation' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'CREATED'],
                    'userErrors' => [],
                ],
            ],
            'extensions' => ['cost' => ['requestedQueryCost' => 10, 'actualQueryCost' => 10, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 990, 'restoreRate' => 100]]],
        ], 200),
    ]);

    $id = $this->client->bulkQuery($this->shop, $this->token, $this->version, 'query { products { edges { node { id } } } }');

    expect($id)->toBe('gid://shopify/BulkOperation/1');
});

it('refuses to start a bulk op when another is in flight on the same shop', function () {
    app(ShopifyBulkOperationLock::class)->acquire($this->shop);

    expect(fn () => $this->client->bulkQuery($this->shop, $this->token, $this->version, 'query { products { edges { node { id } } } }'))
        ->toThrow(\RuntimeException::class, 'already in progress');
});

it('polls waitForBulkOperation until COMPLETED and returns the url', function () {
    Http::fakeSequence("https://{$this->shop}/admin/api/{$this->version}/graphql.json")
        ->push([
            'data' => ['node' => ['status' => 'RUNNING', 'url' => null]],
            'extensions' => ['cost' => ['requestedQueryCost' => 1, 'actualQueryCost' => 1, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 999, 'restoreRate' => 100]]],
        ], 200)
        ->push([
            'data' => ['node' => ['status' => 'COMPLETED', 'url' => 'https://example.com/bulk.jsonl']],
            'extensions' => ['cost' => ['requestedQueryCost' => 1, 'actualQueryCost' => 1, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 999, 'restoreRate' => 100]]],
        ], 200);

    $result = $this->client->waitForBulkOperation($this->shop, $this->token, $this->version, 'gid://shopify/BulkOperation/1', pollIntervalMs: 10, timeoutSeconds: 5);

    expect($result['status'])->toBe('COMPLETED')
        ->and($result['url'])->toBe('https://example.com/bulk.jsonl');
});
```

- [ ] **Step 2: Run tests to see them fail**

Run: `php artisan test --filter=ShopifyAdminClientBulkTest`
Expected: All 3 tests FAIL (`bulkQuery`, `waitForBulkOperation` not defined)

- [ ] **Step 3: Add bulk operation helpers to the client**

Edit `app/Services/Shopify/Client/ShopifyAdminClient.php` — append these public methods:

```php
    /**
     * Start a `bulkOperationRunQuery`. Returns the operation GID.
     *
     * Acquires the per-shop bulk lock; only one bulk op may be in flight at a time.
     * Caller is responsible for calling `waitForBulkOperation()` which releases
     * the lock on terminal state.
     */
    public function bulkQuery(
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        string $query,
    ): string {
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

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, $mutation, ['query' => $query]);
        $userErrors = $response->json('data.bulkOperationRunQuery.userErrors', []);

        if (! empty($userErrors)) {
            $this->bulkLock->release($shopDomain);
            throw new \RuntimeException('Shopify bulkOperationRunQuery userErrors: ' . json_encode($userErrors));
        }

        return (string) $response->json('data.bulkOperationRunQuery.bulkOperation.id');
    }

    /**
     * Start a `bulkOperationRunMutation`. Returns the operation GID.
     * Same locking semantics as bulkQuery.
     */
    public function bulkMutation(
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        string $mutation,
        string $stagedUploadPath,
    ): string {
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

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, $runner, [
            'mutation' => $mutation,
            'stagedUploadPath' => $stagedUploadPath,
        ]);
        $userErrors = $response->json('data.bulkOperationRunMutation.userErrors', []);

        if (! empty($userErrors)) {
            $this->bulkLock->release($shopDomain);
            throw new \RuntimeException('Shopify bulkOperationRunMutation userErrors: ' . json_encode($userErrors));
        }

        return (string) $response->json('data.bulkOperationRunMutation.bulkOperation.id');
    }

    /**
     * Poll a bulk operation until it reaches a terminal state.
     * Releases the per-shop bulk lock on terminal state.
     *
     * @return array{status: string, url: string|null, error_code: string|null}
     */
    public function waitForBulkOperation(
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        string $operationId,
        int $pollIntervalMs = 2000,
        int $timeoutSeconds = 600,
    ): array {
        $query = <<<'GRAPHQL'
        query bulkOperationStatus($id: ID!) {
          node(id: $id) { ... on BulkOperation { id status errorCode url partialDataUrl } }
        }
        GRAPHQL;

        $deadline = microtime(true) + $timeoutSeconds;
        $terminal = ['COMPLETED', 'FAILED', 'CANCELED', 'EXPIRED'];

        while (microtime(true) < $deadline) {
            $response = $this->graphql($shopDomain, $accessToken, $apiVersion, $query, ['id' => $operationId]);
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
```

- [ ] **Step 4: Run bulk tests to verify all pass**

Run: `php artisan test --filter=ShopifyAdminClientBulkTest`
Expected: All 3 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shopify/Client/ShopifyAdminClient.php tests/Feature/Services/Shopify/Client/ShopifyAdminClientBulkTest.php
git commit -m "feat(shopify): add bulk operation helpers with per-shop mutex"
```

---

## Task 11: Migrate `CreateShopifyMetafieldsJob` (canary)

**Rationale:** this job has an inline `graphql()` helper with NO status check — a silent-success throttle bug. Fixing it first proves the client integration.

**Files:**
- Modify: `app/Jobs/Shopify/CreateShopifyMetafieldsJob.php`

- [ ] **Step 1: Read the existing `graphql()` helper to confirm its signature**

Run: `grep -n "private function graphql" app/Jobs/Shopify/CreateShopifyMetafieldsJob.php`
Expected: `377:    private function graphql(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables): \Illuminate\Http\Client\Response`

- [ ] **Step 2: Replace the inline helper with a client call**

Edit `app/Jobs/Shopify/CreateShopifyMetafieldsJob.php`:

1. Remove the `use Illuminate\Support\Facades\Http;` line if no other code in the file uses it.
2. Add `use App\Services\Shopify\Client\ShopifyAdminClient;` at the top.
3. Replace the `graphql(...)` method (lines ~377-389) with a delegating version:

```php
    private function graphql(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        return app(ShopifyAdminClient::class)->graphql(
            $shopDomain,
            $accessToken,
            $apiVersion,
            $query,
            $variables,
            $this->timeout,
        );
    }
```

4. Inside `getExistingDefinitions()` (around line 289) the existing code has `if (! $response->successful()) { ... }` after the graphql call. This is now unreachable (the client throws on non-success), so remove the check.

- [ ] **Step 3: Run the full suite to confirm no regression**

Run: `composer test -- --filter=CreateShopifyMetafieldsJob`
Expected: All tests PASS. If there are no tests covering this job, run `composer test` and confirm no suite breakage.

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/Shopify/CreateShopifyMetafieldsJob.php
git commit -m "refactor(shopify): migrate CreateShopifyMetafieldsJob to ShopifyAdminClient"
```

---

## Task 12: Migrate `ShopifyTeardownService`

**Files:**
- Modify: `app/Services/Shopify/ShopifyTeardownService.php`

- [ ] **Step 1: Replace the `graphql()` helper with a client call**

Edit `app/Services/Shopify/ShopifyTeardownService.php`:

1. Add at the top: `use App\Services\Shopify\Client\ShopifyAdminClient;`
2. Replace the `graphql(...)` method (lines ~556-574) with:

```php
    private function graphql(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        return app(ShopifyAdminClient::class)->graphql(
            $shopDomain,
            $accessToken,
            $apiVersion,
            $query,
            $variables,
        );
    }
```

3. Replace the `revokeOauthToken()` method (lines ~540-554) with:

```php
    private function revokeOauthToken(string $shopDomain, string $accessToken): bool
    {
        try {
            $response = app(ShopifyAdminClient::class)->rest(
                method: 'DELETE',
                shopDomain: $shopDomain,
                accessToken: $accessToken,
                path: '/admin/api_permissions/current.json',
                timeoutSeconds: 15,
                allow401: true,
            );

            return $response->successful() || $response->status() === 401;
        } catch (\App\Exceptions\Shopify\ShopifyTransportException $e) {
            return false;
        }
    }
```

4. Remove the `use Illuminate\Support\Facades\Http;` line if no other code in the file uses it.

- [ ] **Step 2: Run the suite**

Run: `composer test -- --filter=ShopifyTeardown`
Expected: All tests PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Shopify/ShopifyTeardownService.php
git commit -m "refactor(shopify): migrate ShopifyTeardownService to ShopifyAdminClient"
```

---

## Task 13: Migrate `CreateShopifyCollectionsJob`

**Files:**
- Modify: `app/Jobs/Shopify/CreateShopifyCollectionsJob.php`

- [ ] **Step 1: Replace the `graphql()` helper with a client call**

Edit `app/Jobs/Shopify/CreateShopifyCollectionsJob.php`:

1. Add at the top: `use App\Services\Shopify\Client\ShopifyAdminClient;`
2. Replace the `graphql(...)` method (around line 495) with:

```php
    private function graphql(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        return app(ShopifyAdminClient::class)->graphql(
            $shopDomain,
            $accessToken,
            $apiVersion,
            $query,
            $variables,
            $this->timeout ?? null,
        );
    }
```

3. Inside `createCollection()` (around line 341), the check `if (! empty($graphqlErrors)) { Log::warning(...); return null; }` is now dead code — the client throws `ShopifyGraphQLException` before this check is reached. Remove the top-level-errors check (leave the `userErrors` check):

```php
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTION_CREATE, [
            'input' => $input,
        ]);

        // client throws on top-level errors; check userErrors only.
        $userErrors = $response->json('data.collectionCreate.userErrors', []);
```

4. Near line 506 (`throw new \RuntimeException("Shopify GraphQL request failed...")`), remove the `if (! $response->successful())` branch — now unreachable.

5. Remove `use Illuminate\Support\Facades\Http;` if unused elsewhere in the file.

- [ ] **Step 2: Run the suite**

Run: `composer test -- --filter=CreateShopifyCollections`
Expected: All tests PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/Shopify/CreateShopifyCollectionsJob.php
git commit -m "refactor(shopify): migrate CreateShopifyCollectionsJob to ShopifyAdminClient"
```

---

## Task 14: Migrate `SetShopifySetupCompleteJob` + `CreateShopifySalesChannelJob`

**Files:**
- Modify: `app/Jobs/Shopify/SetShopifySetupCompleteJob.php`
- Modify: `app/Jobs/Shopify/CreateShopifySalesChannelJob.php`

- [ ] **Step 1: Migrate `SetShopifySetupCompleteJob`**

Edit `app/Jobs/Shopify/SetShopifySetupCompleteJob.php`:

1. Add `use App\Services\Shopify\Client\ShopifyAdminClient;` at the top.
2. Replace the two `Http::withHeaders([...])->post(...)` blocks (around lines 82 and 96) with `app(ShopifyAdminClient::class)->graphql(...)` calls. Pass `$shopDomain`, `$accessToken`, `$apiVersion`, `$query`, `$variables` in that order.

For example, replace:

```php
            $shopGidResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout($this->timeout)->post(
                "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
                ['query' => $shopGidQuery]
            );
```

with:

```php
            $shopGidResponse = app(ShopifyAdminClient::class)->graphql(
                $shopDomain,
                $accessToken,
                $apiVersion,
                $shopGidQuery,
            );
```

3. Remove `use Illuminate\Support\Facades\Http;` if unused.

- [ ] **Step 2: Migrate `CreateShopifySalesChannelJob`**

Same pattern — replace direct `Http::withHeaders(...)->post(...)` calls (around lines 106 and 165) with `app(ShopifyAdminClient::class)->graphql(...)`.

- [ ] **Step 3: Run the suite**

Run: `composer test -- --filter="SetShopifySetupComplete|CreateShopifySalesChannel"`
Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/Shopify/SetShopifySetupCompleteJob.php app/Jobs/Shopify/CreateShopifySalesChannelJob.php
git commit -m "refactor(shopify): migrate setup-complete + sales-channel jobs to ShopifyAdminClient"
```

---

## Task 15: Migrate remaining jobs

**Files:**
- Modify: `app/Jobs/Shopify/CreateStorefrontAccessTokenJob.php`
- Modify: `app/Jobs/Shopify/RegisterShopifyWebhooksJob.php`
- Modify: `app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php`
- Modify: `app/Jobs/Shopify/SyncShopifyBrandDesignJob.php`
- Modify: `app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php`

- [ ] **Step 1: Migrate each file — same mechanical pattern**

For each file above, perform these edits:

1. Add `use App\Services\Shopify\Client\ShopifyAdminClient;` at the top.
2. Find every `Http::withHeaders(['X-Shopify-Access-Token' => $accessToken, ...])->timeout(...)->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", ...)` pattern.
3. Replace with `app(ShopifyAdminClient::class)->graphql($shopDomain, $accessToken, $apiVersion, $query, $variables)`.
4. Remove any downstream `if (! $response->successful()) { throw ... }` branches — the client throws `ShopifyTransportException` already.
5. Remove `use Illuminate\Support\Facades\Http;` if it's no longer referenced in the file.

- [ ] **Step 2: Run the full test suite**

Run: `composer test`
Expected: All tests PASS. If a Shopify-related test fails because it was asserting on `Http::assertSent` in a way that now runs through the client, update the assertion to still match the underlying `Http::fake()` expectation.

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/Shopify/CreateStorefrontAccessTokenJob.php \
        app/Jobs/Shopify/RegisterShopifyWebhooksJob.php \
        app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php \
        app/Jobs/Shopify/SyncShopifyBrandDesignJob.php \
        app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php
git commit -m "refactor(shopify): migrate remaining Shopify jobs to ShopifyAdminClient"
```

---

## Task 16: Migrate remaining services

**Files:**
- Modify: `app/Services/Shopify/BrandDesignImporter.php`
- Modify: `app/Services/Shopify/ShopifyDataResyncService.php`

- [ ] **Step 1: Migrate `BrandDesignImporter`**

Edit `app/Services/Shopify/BrandDesignImporter.php`:

1. Add `use App\Services\Shopify\Client\ShopifyAdminClient;`.
2. Replace all three `Http::timeout(20)->withHeaders(...)->post(...)` calls (lines ~174, ~209, ~240) with `app(ShopifyAdminClient::class)->graphql(...)` calls passing the existing `$shopDomain`, `$accessToken`, `$apiVersion`, `$query`, `$variables`.
3. The third call (asset fetch around line 240) may be REST, not GraphQL — if the URL path is `/admin/api/.../themes/.../assets.json`, use `app(ShopifyAdminClient::class)->rest('GET', $shopDomain, $accessToken, $path)` instead.
4. Remove `use Illuminate\Support\Facades\Http;` if unused.

- [ ] **Step 2: Migrate `ShopifyDataResyncService`**

Edit `app/Services/Shopify/ShopifyDataResyncService.php`:

1. Add `use App\Services\Shopify\Client\ShopifyAdminClient;`.
2. Replace the single `Http::timeout(20)->withHeaders(...)->post(...)` call (line ~89) with `app(ShopifyAdminClient::class)->graphql(...)`.
3. Remove `use Illuminate\Support\Facades\Http;` if unused.

- [ ] **Step 3: Run the full test suite**

Run: `composer test`
Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Shopify/BrandDesignImporter.php app/Services/Shopify/ShopifyDataResyncService.php
git commit -m "refactor(shopify): migrate BrandDesignImporter + ShopifyDataResyncService to ShopifyAdminClient"
```

---

## Task 17: End-to-end verification

- [ ] **Step 1: Grep to confirm no direct Shopify Admin `Http::` calls remain**

Run: `grep -rn "admin/api/.*graphql.json\|admin/api_permissions" app/ | grep -v "Client/ShopifyAdminClient.php"`
Expected: zero results. If any remain, migrate them using the Task 15 pattern and add a follow-up commit.

- [ ] **Step 2: Run the full test suite**

Run: `composer test`
Expected: All tests PASS, zero Laravel migration violations, no deprecation warnings.

- [ ] **Step 3: Run Laravel Pint for style**

Run: `php artisan pint`
Expected: Style-clean. If changes are made, add a style-fix commit.

- [ ] **Step 4: Manual smoke test (local)**

Run: `composer dev` — then trigger a Shopify onboarding flow locally (or replay a recorded webhook) and confirm:
- Metafield definitions are created
- Collections are created and published
- No warnings in `storage/logs/laravel.log` about `shopify.client.graphql_error` or `shopify.client.throttled`
- `shopify.client.request` debug logs appear with populated `bucket_remaining` values

- [ ] **Step 5: Check Nightwatch post-deploy**

After deploying to staging, use the Nightwatch MCP (`list_issues` on the Partna application) to confirm:
- No new exceptions with class containing `Shopify` or `Throttled`
- No regressions in previously-passing Shopify job success rates

- [ ] **Step 6: Commit any final cleanup**

```bash
git add -u
git commit -m "chore(shopify): pint + final client migration cleanup"
```

- [ ] **Step 7: Open PR**

```bash
gh pr create --title "Shopify Admin API client with cost-aware throttling" --body "$(cat <<'EOF'
## Summary
- Centralises all 20 Shopify Admin API call sites into a single `ShopifyAdminClient`
- Adds cost-aware throttling via atomic Redis Lua token bucket, reconciled from `throttleStatus` on every response
- Tracks per-query actual/requested cost ratio for conservative pre-reservation
- Typed exception hierarchy (`ShopifyThrottledException`, `ShopifyGraphQLException`, `ShopifyTransportException`) — queue `backoff()` retry handles overflow
- Bulk operation helpers with per-shop mutex, scaffolded for future use
- Observability hooks via structured logs (`shopify.client.*`) — swap for StatsD later

## Bug fixes along the way
- `CreateShopifyMetafieldsJob::graphql()` had no HTTP status check — a throttled response (HTTP 200 + `errors: [THROTTLED]`) silently succeeded with no data. Now throws.
- `ShopifyTeardownService::graphql()` threw on non-2xx but not on THROTTLED responses. Now throws.
- `CreateShopifyCollectionsJob::createCollection()` logged top-level GraphQL errors and returned null — a throttled collection was silently dropped. Now throws so the job retries.

## Test plan
- [ ] `composer test` passes
- [ ] Local Shopify onboarding end-to-end: metafields → collections → sales channel → setup-complete
- [ ] Nightwatch shows no new `Shopify*Exception` after staging deploy
- [ ] Log inspection: `shopify.client.request` events appear with populated `bucket_remaining` values
EOF
)"
```

---

## Self-Review Checklist

**Spec coverage:** Every design call in the plan header maps to at least one task:
- Atomic token bucket → Task 3
- Reconcile > estimate → Task 7
- Per-query cost learning → Task 4 + Task 7 (record on reconcile)
- REST reactive 429 handling → Task 9
- Bulk ops scaffolded → Task 10
- Response passthrough for minimal migration → Tasks 11-16

**Placeholders:** None — every code step contains full implementation. Migration tasks reference existing line numbers and show full replacement snippets.

**Type consistency:** `graphql()` returns `Illuminate\Http\Client\Response` in all references. `tryAcquire` returns `array{acquired: bool, remaining: int, wait_ms: int}` consistently. `waitForBulkOperation` returns `array{status, url, error_code}` consistently. `rest()` takes the same parameter order throughout.

**Bug fixes discovered during migration:** Tasks 11 (`CreateShopifyMetafieldsJob`), 12 (`ShopifyTeardownService`), and 13 (`CreateShopifyCollectionsJob`) each remove a silent-success code path as a side effect. Called out in the PR description.
