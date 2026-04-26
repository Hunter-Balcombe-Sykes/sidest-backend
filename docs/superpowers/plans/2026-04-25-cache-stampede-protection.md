# Cache Stampede Protection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add single-flight cache regeneration to all unprotected high-traffic analytics caches via a shared `CacheLockService` helper, eliminating cache stampede risk on the dashboard hot paths.

**Architecture:** Extract the proven single-flight pattern already used in `SiteCacheService::getPublicSitePayload` (`Cache::lock` + `block()` + double-check + `try/finally`) into a reusable `CacheLockService::rememberLocked()` helper. Apply it to the 6 unprotected analytics call sites identified in the audit. Existing aggregate-rebuild jobs (`*AnalyticsAggregateService`) already use `pg_advisory_xact_lock` for write-side dedup — this plan only touches read-side cache regeneration.

**Tech Stack:** Laravel 12, Redis (cache driver), `Illuminate\Contracts\Cache\Lock`, Pest 4 + PHPUnit, Mockery.

**Out of scope (deferred to follow-ups):**
- `ProfessionalCacheService` methods (return nullable values; need a separate `rememberLockedNullable` variant with sentinel handling — not blocking the primary stampede targets)
- `HydrogenBrandDesignController` (5s TTL is intentional SWR-style; locking would defeat the design)
- `VerifySupabaseJwt` JWKS cache (low blast radius; optional follow-up)

---

## File Structure

**Create:**
- `app/Services/Cache/CacheLockService.php` — single-flight cache helper with `rememberLocked()` method
- `tests/Feature/Cache/CacheLockServiceTest.php` — Pest tests covering hit/miss/double-check/lock-timeout/exception paths

**Modify:**
- `app/Services/Cache/AnalyticsCacheService.php` (lines 13–34, 36–68) — replace `Cache::remember` calls in `getVisitStats` and `getClickStats`
- `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php` (line 35) — replace `Cache::remember` in `overview()`
- `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php` (line 35 area) — replace `Cache::remember` in `overview()`
- `app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php` (line 53) — replace `Cache::remember` in `overview()`
- `app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php` (line 94) — replace `Cache::remember` in `summary()`

---

## Task 1: Create CacheLockService helper

**Files:**
- Create: `app/Services/Cache/CacheLockService.php`

- [ ] **Step 1.1: Create the helper class**

Create `app/Services/Cache/CacheLockService.php` with this exact content:

```php
<?php

namespace App\Services\Cache;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Single-flight cache regeneration helper.
 *
 * Wraps Laravel's Cache::remember with a Cache::lock so that under concurrent
 * load only one request rebuilds an expired value while others wait and read
 * the freshly-filled cache. Models the proven pattern in
 * SiteCacheService::getPublicSitePayload.
 *
 * Use this for any cached value that:
 *   - is hot (likely to be requested concurrently when it expires), AND
 *   - is expensive to regenerate (multiple DB queries, joins, external calls).
 *
 * Closures must return a non-null value. Storing null requires sentinel
 * handling that this helper intentionally does not provide.
 */
class CacheLockService
{
    /**
     * Get the value at $key, or compute it via $callback under a single-flight lock.
     *
     * @param  string  $key       Cache key (the lock key is auto-derived as 'lock:'.$key)
     * @param  DateTimeInterface|int  $ttl  Same TTL semantics as Cache::remember (DateTime or seconds)
     * @param  Closure(): mixed  $callback  Closure that produces the value on miss; must not return null
     * @param  int  $lockSeconds   How long the lock is held before auto-expiring (must exceed worst-case closure runtime)
     * @param  int  $blockSeconds  How long a waiting request blocks for the lock before falling through
     */
    public function rememberLocked(
        string $key,
        DateTimeInterface|int $ttl,
        Closure $callback,
        int $lockSeconds = 10,
        int $blockSeconds = 5,
    ): mixed {
        // Fast path: value already cached, no lock needed.
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // Cache miss — acquire a per-key fill lock so only one process rebuilds.
        $lock = Cache::lock('lock:'.$key, $lockSeconds);

        try {
            $lock->block($blockSeconds);
        } catch (LockTimeoutException) {
            // Another process is filling the cache but took too long.
            // Return whatever is now cached, or fall through to compute as a last resort
            // so the user never gets nothing back. The stampede risk on this edge case
            // is bounded to requests that arrive in the timeout window.
            $warm = Cache::get($key);
            if ($warm !== null) {
                return $warm;
            }

            return $callback();
        }

        try {
            // Double-check: another process may have filled the cache while we waited.
            $rechecked = Cache::get($key);
            if ($rechecked !== null) {
                return $rechecked;
            }

            $value = $callback();
            Cache::put($key, $value, $ttl);

            return $value;
        } finally {
            // Always release — even if the closure threw — so we don't hold the lock
            // for its full TTL after a failure.
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock already released or driver doesn't support release-after-expiry; ignore.
            }
        }
    }
}
```

- [ ] **Step 1.2: Verify the file compiles**

Run: `php -l app/Services/Cache/CacheLockService.php`
Expected: `No syntax errors detected in app/Services/Cache/CacheLockService.php`

- [ ] **Step 1.3: Commit**

```bash
git add app/Services/Cache/CacheLockService.php
git commit -m "feat(cache): add CacheLockService for single-flight regeneration"
```

---

## Task 2: Test the helper

**Files:**
- Create: `tests/Feature/Cache/CacheLockServiceTest.php`

The test plan covers five behaviors: cache hit, cache miss + store, double-check inside lock, lock timeout fallback, and exception in closure releasing the lock. Tests use the `array` driver for hit/store assertions and Mockery for lock-specific assertions (the array driver does not support `Cache::lock`).

- [ ] **Step 2.1: Write the failing test file**

Create `tests/Feature/Cache/CacheLockServiceTest.php`:

```php
<?php

use App\Services\Cache\CacheLockService;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mockery as M;

beforeEach(function () {
    Cache::flush();
    $this->service = new CacheLockService();
});

afterEach(function () {
    M::close();
});

it('returns cached value without acquiring lock on cache hit', function () {
    Cache::put('test:key', ['cached' => true], 60);

    // If a lock were attempted, this Mockery expectation would fail the test.
    Cache::shouldReceive('get')->with('test:key')->andReturn(['cached' => true])->once();
    Cache::shouldReceive('lock')->never();

    $result = $this->service->rememberLocked(
        'test:key',
        60,
        fn () => throw new RuntimeException('closure should not run'),
    );

    expect($result)->toBe(['cached' => true]);
});

it('runs closure and stores result on cache miss', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:miss')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:miss', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->with('test:miss', ['fresh' => 'value'], 60)->once();

    $result = $this->service->rememberLocked(
        'test:miss',
        60,
        fn () => ['fresh' => 'value'],
    );

    expect($result)->toBe(['fresh' => 'value']);
});

it('skips closure when cache fills during lock wait (double-check)', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    // First Cache::get returns null (initial miss); second returns filled value
    // (another process filled it while we were waiting on the lock).
    Cache::shouldReceive('get')
        ->with('test:double')
        ->twice()
        ->andReturn(null, ['filled' => 'by other']);
    Cache::shouldReceive('lock')->with('lock:test:double', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $closureRan = false;
    $result = $this->service->rememberLocked(
        'test:double',
        60,
        function () use (&$closureRan) {
            $closureRan = true;

            return ['fresh' => 'should not run'];
        },
    );

    expect($result)->toBe(['filled' => 'by other']);
    expect($closureRan)->toBeFalse();
});

it('falls through to closure when lock acquisition times out and cache is still empty', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException());

    // Initial miss, then re-check after timeout still returns null.
    Cache::shouldReceive('get')->with('test:timeout')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:timeout', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $result = $this->service->rememberLocked(
        'test:timeout',
        60,
        fn () => ['last' => 'resort'],
    );

    expect($result)->toBe(['last' => 'resort']);
});

it('returns cached value on lock timeout if cache filled in the meantime', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException());

    Cache::shouldReceive('get')
        ->with('test:timeout-filled')
        ->twice()
        ->andReturn(null, ['filled' => 'while waiting']);
    Cache::shouldReceive('lock')->with('lock:test:timeout-filled', 10)->once()->andReturn($lock);

    $closureRan = false;
    $result = $this->service->rememberLocked(
        'test:timeout-filled',
        60,
        function () use (&$closureRan) {
            $closureRan = true;

            return ['should' => 'not run'];
        },
    );

    expect($result)->toBe(['filled' => 'while waiting']);
    expect($closureRan)->toBeFalse();
});

it('releases lock when closure throws', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:throw')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:throw', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $call = fn () => $this->service->rememberLocked(
        'test:throw',
        60,
        fn () => throw new RuntimeException('boom'),
    );

    expect($call)->toThrow(RuntimeException::class, 'boom');
});

it('honours custom lockSeconds and blockSeconds', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(2)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:custom')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:custom', 30)->once()->andReturn($lock);
    Cache::shouldReceive('put')->with('test:custom', 'v', 60)->once();

    $result = $this->service->rememberLocked(
        'test:custom',
        60,
        fn () => 'v',
        lockSeconds: 30,
        blockSeconds: 2,
    );

    expect($result)->toBe('v');
});
```

- [ ] **Step 2.2: Run the tests and verify they fail or pass cleanly**

Run: `./vendor/bin/pest tests/Feature/Cache/CacheLockServiceTest.php --colors=never`
Expected: All 7 tests PASS (Task 1 already created the implementation; this task verifies it).

If any test fails, fix the helper in `app/Services/Cache/CacheLockService.php` to match the test's expected behavior. Do not change the tests to match a buggy implementation.

- [ ] **Step 2.3: Commit**

```bash
git add tests/Feature/Cache/CacheLockServiceTest.php
git commit -m "test(cache): cover CacheLockService single-flight behaviors"
```

---

## Task 3: Apply to AnalyticsCacheService

**Files:**
- Modify: `app/Services/Cache/AnalyticsCacheService.php`

- [ ] **Step 3.1: Replace Cache::remember with rememberLocked in both methods**

Edit `app/Services/Cache/AnalyticsCacheService.php`. The full new file contents:

```php
<?php

namespace App\Services\Cache;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

// V2: Visit/click stats caching with version-token invalidation for bulk cache busting.
class AnalyticsCacheService
{
    public function __construct(private CacheLockService $cacheLock) {}

    public function getVisitStats(string $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = CacheKeyGenerator::analyticsVisits(
            $professionalId,
            $startDate->format('Ymd'),
            $endDate->format('Ymd')
        );

        return $this->cacheLock->rememberLocked($cacheKey, now()->addMinutes(5), function () use ($professionalId, $startDate, $endDate) {
            return SiteVisit::where('professional_id', $professionalId)
                ->whereBetween('occurred_at', [$startDate, $endDate])
                ->selectRaw('
                    COUNT(*) as total_visits,
                    COUNT(DISTINCT visitor_id) as unique_visitors,
                    COUNT(DISTINCT DATE(occurred_at)) as days_with_visits,
                    COUNT(DISTINCT country_code) as unique_countries,
                    COUNT(DISTINCT device_type) as device_types
                ')
                ->first()
                ->toArray();
        });
    }

    public function getClickStats(string $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = CacheKeyGenerator::analyticsClicks(
            $professionalId,
            $startDate->format('Ymd'),
            $endDate->format('Ymd')
        );

        return $this->cacheLock->rememberLocked($cacheKey, now()->addMinutes(5), function () use ($professionalId, $startDate, $endDate) {
            return LinkClick::runForBlockForeignKey(
                function (string $blockColumn) use ($professionalId, $startDate, $endDate) {
                    return LinkClick::where('professional_id', $professionalId)
                        ->whereBetween('occurred_at', [$startDate, $endDate])
                        ->selectRaw("
                            COUNT(*) as total_clicks,
                            COUNT(DISTINCT visitor_id) as unique_clickers,
                            COUNT(DISTINCT {$blockColumn}) as links_clicked
                        ")
                        ->first()
                        ?->toArray() ?? [
                            'total_clicks' => 0,
                            'unique_clickers' => 0,
                            'links_clicked' => 0,
                        ];
                },
                [
                    'total_clicks' => 0,
                    'unique_clickers' => 0,
                    'links_clicked' => 0,
                ]
            );
        });
    }

    public function invalidateAnalytics(string $professionalId): void
    {
        // Bump the version token so every cached summary for this professional
        // becomes unreachable immediately, regardless of date-range or granularity.
        // The stale entries will expire on their own TTL (≤ 24 h).
        Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($professionalId));

        // Delete the rolling 90-day window of visit and click stat keys.
        $keys = [];
        $end = Carbon::now();

        for ($i = 0; $i < 90; $i++) {
            $date = $end->copy()->subDays($i);
            $start = $date->format('Ymd');
            $endStr = $end->format('Ymd');

            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $start, $endStr);
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $start, $endStr);
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
    }
}
```

The only changes are: (1) added `use` import for `CacheLockService` (implicit via same namespace, no `use` needed), (2) added a constructor with promoted `CacheLockService` property, (3) replaced both `Cache::remember(...)` calls with `$this->cacheLock->rememberLocked(...)`.

- [ ] **Step 3.2: Run the existing analytics cache tests**

Run: `./vendor/bin/pest tests/Feature/Cache/AnalyticsCacheKeyParityTest.php --colors=never`
Expected: PASS — the cache key generation logic is unchanged, so parity tests must still pass.

- [ ] **Step 3.3: Run any feature tests that depend on AnalyticsCacheService**

Run: `./vendor/bin/pest tests/Feature/Analytics/ --colors=never`
Expected: All existing tests PASS — behavior is unchanged from the caller's perspective; only the regeneration coordination is new.

If any test fails because Laravel can't resolve `CacheLockService` in a test context, ensure it has no constructor dependencies (it shouldn't) — the container will auto-instantiate it.

- [ ] **Step 3.4: Commit**

```bash
git add app/Services/Cache/AnalyticsCacheService.php
git commit -m "feat(cache): protect analytics visit/click stats with single-flight lock"
```

---

## Task 4: Apply to BrandCommerceAnalyticsController

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`

- [ ] **Step 4.1: Inject CacheLockService and replace Cache::remember**

Edit `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`.

Find the `use` block at the top (lines 3–14) and replace it with:

```php
namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
```

Note: the `Illuminate\Support\Facades\Cache` import is removed; `Cache` is no longer referenced.

Find the class declaration `class BrandCommerceAnalyticsController extends ApiController { use ResolveCurrentProfessional;` and immediately after the `use` trait line, add a constructor:

```php
class BrandCommerceAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(private CacheLockService $cacheLock) {}
```

In the `overview()` method, find this line:

```php
        return $this->success(Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $filters): array {
```

Replace with:

```php
        return $this->success($this->cacheLock->rememberLocked($cacheKey, now()->addMinutes(5), function () use ($professionalId, $filters): array {
```

The closing parenthesis on the closure (currently `}));` at the end of the `overview` method) stays the same — the `Cache::remember(...)` / `rememberLocked(...)` argument shape is identical.

- [ ] **Step 4.2: Verify the file is syntactically valid**

Run: `php -l app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4.3: Run the controller's tests**

Run: `./vendor/bin/pest tests/Feature/Analytics --colors=never --filter=BrandCommerce`
Expected: PASS. If no tests match the filter, run the full Analytics directory:
`./vendor/bin/pest tests/Feature/Analytics --colors=never`

- [ ] **Step 4.4: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php
git commit -m "feat(cache): single-flight lock for brand commerce analytics overview"
```

---

## Task 5: Apply to AffiliateCommerceAnalyticsController

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php`

- [ ] **Step 5.1: Inject CacheLockService and replace Cache::remember**

Open `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php`.

**Change 1 — Imports.** Add `use App\Services\Cache\CacheLockService;` to the existing `use` block. Before removing the `Cache` facade import, run:

```bash
grep -n 'Cache::' app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php
```

If the only match is the `Cache::remember(...)` line we're about to replace, remove `use Illuminate\Support\Facades\Cache;`. If `Cache::` appears elsewhere (e.g. `Cache::get`, `Cache::increment` for a version token), keep the import.

**Change 2 — Constructor.** Inside the class body, immediately after any `use TraitName;` trait lines, add:

```php
public function __construct(private CacheLockService $cacheLock) {}
```

If a constructor already exists, append `private CacheLockService $cacheLock` to its parameter list (use constructor property promotion).

**Change 3 — Replace the Cache::remember call.** In the `overview()` method, find the line that begins:

```php
        return $this->success(Cache::remember($cacheKey, now()->addMinutes(5), function () use (
```

and replace **only** the `Cache::remember(` portion with `$this->cacheLock->rememberLocked(`:

```php
        return $this->success($this->cacheLock->rememberLocked($cacheKey, now()->addMinutes(5), function () use (
```

The closure body, captured variables, return type, and closing parenthesis pattern (`}));`) are unchanged. Argument shape (`$key, $ttl, Closure $callback`) is identical between `Cache::remember` and `rememberLocked`.

- [ ] **Step 5.2: Verify the file is syntactically valid**

Run: `php -l app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php`
Expected: `No syntax errors detected`

- [ ] **Step 5.3: Run the controller's tests**

Run: `./vendor/bin/pest tests/Feature/Analytics --colors=never --filter=AffiliateCommerce`
Expected: PASS (or "No tests executed" — fall back to full Analytics suite).

- [ ] **Step 5.4: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php
git commit -m "feat(cache): single-flight lock for affiliate commerce analytics overview"
```

---

## Task 6: Apply to BookingAnalyticsController

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php`

- [ ] **Step 6.1: Inject CacheLockService and replace Cache::remember**

Apply the same three-part transformation as Task 4/5:

1. **Add** `use App\Services\Cache\CacheLockService;` to the imports. Remove `use Illuminate\Support\Facades\Cache;` only if no other `Cache::` references exist in the file (`grep -n 'Cache::' app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php`).

2. **Add** a constructor inside the class:

```php
public function __construct(private CacheLockService $cacheLock) {}
```

3. In the `overview()` method (around line 53), **replace**:

```php
        return $this->success(Cache::remember($cacheKey, $ttl, function () use ($professionalId, $timezone, $metricsContext): array {
```

with:

```php
        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () use ($professionalId, $timezone, $metricsContext): array {
```

The closing of the call site (`}));` at the end of the closure) is unchanged.

- [ ] **Step 6.2: Verify and run tests**

Run: `php -l app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php`
Expected: `No syntax errors detected`

Run: `./vendor/bin/pest tests/Feature/Analytics --colors=never --filter=Booking`
Expected: PASS (or fall back to full Analytics suite).

- [ ] **Step 6.3: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php
git commit -m "feat(cache): single-flight lock for booking analytics overview"
```

---

## Task 7: Apply to ProfessionalAnalyticsController

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php`

This is the largest and highest-risk caller (the main professional dashboard summary). The `Cache::get(...)` for the version token (around lines 80–83) **must remain unchanged** — only the `Cache::remember(...)` wrapping the heavy aggregate closure (line 94) is replaced.

- [ ] **Step 7.1: Inject CacheLockService and replace only the regeneration call**

In `app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php`:

1. **Add** `use App\Services\Cache\CacheLockService;` to the imports. **Keep** the existing `use Illuminate\Support\Facades\Cache;` import — it's still used for `Cache::get(...)` on the version token.

2. **Add** a constructor inside the class. If a constructor already exists (it likely does not, but verify), append `CacheLockService $cacheLock` as a promoted parameter and assign in the body. If no constructor, insert immediately after any `use TraitName;` lines:

```php
public function __construct(private CacheLockService $cacheLock) {}
```

3. **Replace** only the `Cache::remember(...)` call at line 94. Find:

```php
        $data = Cache::remember($cacheKey, $cacheTTL, function () use ($professional, $from, $to, $site, $professionalTimezone, $useHourlyBuckets) {
```

Replace with:

```php
        $data = $this->cacheLock->rememberLocked($cacheKey, $cacheTTL, function () use ($professional, $from, $to, $site, $professionalTimezone, $useHourlyBuckets) {
```

The closure body, the `Cache::get(...)` for the version token (lines 80–83), and `Cache::increment(...)` calls elsewhere in the controller (if any) are **unchanged**.

- [ ] **Step 7.2: Verify and run tests**

Run: `php -l app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php`
Expected: `No syntax errors detected`

Run: `./vendor/bin/pest tests/Feature/Analytics --colors=never --filter=ProfessionalAnalytics`
Expected: PASS (or fall back to full Analytics suite).

- [ ] **Step 7.3: Commit**

```bash
git add app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php
git commit -m "feat(cache): single-flight lock for professional analytics summary"
```

---

## Task 8: Final verification

- [ ] **Step 8.1: Run full test suite**

Run: `composer test`
Expected: All tests pass; the no-Laravel-migrations guard passes (no migrations were added).

If the test suite fails, do not proceed. Diagnose: most likely culprits are (a) a controller test that mocks `Cache::remember` directly — those tests need to mock `CacheLockService::rememberLocked` instead, or be refactored to mock the underlying `Cache::get`/`Cache::lock`/`Cache::put`; or (b) a service test that constructs `AnalyticsCacheService` without the `CacheLockService` argument — fix the test to use container resolution (`app(AnalyticsCacheService::class)`) or pass `new CacheLockService()` explicitly.

- [ ] **Step 8.2: Run Laravel Pint to confirm style**

Run: `php artisan pint --test`
Expected: Pint reports no style violations. If any are reported, run `php artisan pint` to auto-fix and re-run.

- [ ] **Step 8.3: Manual sanity check — verify lock keys are namespaced**

Run: `grep -rn "rememberLocked" app/`
Expected: 6 call sites — 2 in `AnalyticsCacheService.php`, 1 each in `BrandCommerceAnalyticsController.php`, `AffiliateCommerceAnalyticsController.php`, `BookingAnalyticsController.php`, `ProfessionalAnalyticsController.php`. All keys come from `CacheKeyGenerator::*` so they're already namespaced; the helper auto-derives lock keys as `'lock:'.$key`.

- [ ] **Step 8.4: Confirm no orphan Cache::remember on analytics paths**

Run: `grep -rn "Cache::remember" app/Http/Controllers/Api/Professional/`
Expected: zero results in the Analytics, Booking subdirectories. Any remaining matches in other Professional controller subdirs are out of scope for this plan and acceptable.

- [ ] **Step 8.5: Final commit (if any pint fixes were applied)**

If Pint applied fixes:

```bash
git add -A
git commit -m "style: pint auto-fix after cache stampede protection"
```

If no Pint fixes were needed, skip this step.

---

## Self-review checklist

The 6 unprotected analytics call sites identified in the audit:

| Site | Task |
|---|---|
| `AnalyticsCacheService::getVisitStats` (line 13) | Task 3 |
| `AnalyticsCacheService::getClickStats` (line 36) | Task 3 |
| `BrandCommerceAnalyticsController::overview` (line 35) | Task 4 |
| `AffiliateCommerceAnalyticsController::overview` (line 35) | Task 5 |
| `BookingAnalyticsController::overview` (line 53) | Task 6 |
| `ProfessionalAnalyticsController::summary` (line 94) | Task 7 |

All covered. Each modified file has a corresponding test step.

**Risks accepted in this plan:**
- The lock-timeout fallback runs the closure unprotected (last-resort behavior). This is by design — never block the user beyond `blockSeconds` (default 5s) — and matches the existing `SiteCacheService` pattern.
- Tests rely on Mockery for lock semantics rather than a real lock-supporting cache driver, because the test cache driver (`array`) does not support `Cache::lock`. Real-driver behavior is exercised in production by virtue of using the same Laravel cache contracts.
