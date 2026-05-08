# Affiliate Projections (Tier 1 + Tier 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `GET /affiliate/projections` endpoint that returns straight-line annual & year-end forecasts, run-rate, momentum, YTD, best-month, and engagement metrics for an authenticated affiliate, computed entirely from the trigger-maintained `commerce.brand_affiliate_rollup` table.

**Architecture:** Thin controller → form-validated request → service layer doing all math → resource-shaped JSON response, fronted by the existing `CacheLockService::rememberLocked` SWR layer and push-invalidated through the existing `AnalyticsCacheService::invalidateAnalytics()` path so every order/refund/cancel webhook busts projections automatically. Defense-in-depth on auth: Supabase RLS on the rollup table is the primary gate, plus a `viewProjections` ability on `CommissionPolicy` enforced via `authorizeForUser()` (CLAUDE.md compliance).

**Tech Stack:** PHP 8.2 / Laravel 12 / Pest 4 / PostgreSQL (Supabase) / Redis / `CacheLockService` / Mockery.

---

## Context for the engineer (read first)

You are implementing **one new read-only endpoint** for affiliates to see "at this rate, you'll earn $X by year-end". All math comes from `commerce.brand_affiliate_rollup`, which is a per-day rollup table maintained by triggers on `commerce.orders`. **Never query `commerce.orders` directly** — the rollup is the read path.

Key invariants you must respect:
- **Net commission** = `commission_cents - reversed_commission_cents`. Always net, never gross. Reversals are real economic events that affect projection accuracy.
- **Multi-currency**: the rollup keys on `currency_code char(3)`. Most affiliates have one currency, but the API must support multiple. Never sum across currencies.
- **UTC `day` column**: rollup days are UTC dates. Year-end and YTD calculations must use the *professional's* timezone (`$professional->timezone`), then convert to UTC dates for the SQL filter.
- **Money is integer cents**, always. Never floats in storage or transport. `_cents` suffix on every money field.
- **Window selection is adaptive**: 90d if available, else 60d, else 30d, else 14d. <14d returns `status: insufficient_data`.

Why a new endpoint and not extend the existing `overview` action: different cache key (no date range — projections are absolute "as of now"), different TTL (5 min vs 1 min), different policy ability, different response shape. Splitting keeps each cacheable independently and avoids invalidation coupling.

### Final response shape (lock this — frontend will code against it)

**Happy path:**
```json
{
  "data": {
    "as_of": "2026-05-07T14:23:11+00:00",
    "data_history_days": 47,
    "status": "ok",
    "window": {
      "days": 30,
      "from": "2026-04-07",
      "to": "2026-05-06"
    },
    "engagement": {
      "earning_days_count": 22,
      "active_brand_count": 2
    },
    "by_currency": [
      {
        "currency_code": "USD",
        "run_rate": {
          "commission_cents_per_day": 4231,
          "orders_per_day": 1.2
        },
        "projections": {
          "annual_commission_cents": 1544315,
          "year_end_commission_cents": 1102000,
          "annual_orders": 438,
          "confidence": "medium"
        },
        "momentum": {
          "pct_change_vs_prior_window": 0.23,
          "prior_run_rate_cents_per_day": 3440
        },
        "ytd": {
          "commission_cents": 612000,
          "orders_count": 178,
          "best_month": "2026-03",
          "best_month_commission_cents": 184000
        }
      }
    ]
  }
}
```

**Insufficient data path** (data_history_days < 14):
```json
{
  "data": {
    "as_of": "2026-05-07T14:23:11+00:00",
    "data_history_days": 5,
    "status": "insufficient_data",
    "window": null,
    "engagement": { "earning_days_count": 5, "active_brand_count": 1 },
    "by_currency": []
  }
}
```

`by_currency` is sorted descending by in-window net commission, so the frontend renders `by_currency[0]` as the primary.

---

## Cross-cutting requirements

### Security
- **Auth gate**: `auth.professional` middleware (already on the route group) populates `$request->attributes->get('professional')`. Reject unauthenticated requests with 401 (handled by middleware).
- **Defense-in-depth**: Controller calls `$this->authorizeForUser($professional, 'viewProjections', $skeleton)` against `CommissionPolicy` *before* hitting the cache. The skeleton is a `BrandAffiliateRollup` instance with only `affiliate_professional_id` set. If the policy denies, returns 403 (Laravel default).
- **RLS at DB layer**: The rollup table already has an RLS policy (`rollup_party_select`, migration `20260506000000_create_orders_schema.sql:367-374`) that only lets a professional see rows where they're the brand or affiliate. We do **not** rely on RLS alone, but it's our last line if a bug ever bypasses the policy gate.
- **No reflected user input in cache keys**: Cache key is `analytics:commerce:affiliate:projections:v1:{professional_id}` — only the authenticated UUID, never a query param. Prevents cache-poisoning via crafted query strings.
- **No secret leakage**: Only money totals and counts are returned. No order IDs, customer info, or raw event data.

### Sanitization
- **Form Request validation**: Single optional query param `window_days` (allowlist: `14|30|60|90`). Anything else fails validation with 422. No free-form integer accepted — prevents pathologically large windows that would blow the SQL planner.
- **Output coercion**: Resource class casts every numeric field via `(int)` or `(float)`. Strings are HTML-encoded by Laravel's default JSON serializer (no raw HTML appears in any field, but defense in depth).
- **Currency code allowlist**: We trust the rollup table's `currency_code` (it's constrained to 3-char ISO). No further sanitization needed, but the Resource passes it through as-is — never interpolates into a string format.

### Scalability
- **Single-source aggregation**: One SQL query against the rollup, returning per-currency, per-window aggregates. Worst case: 100 brands × 90 days = 9,000 rows scanned per affiliate. With the existing PK `(day, brand_professional_id, affiliate_professional_id, currency_code)`, the affiliate filter is index-friendly via a partial scan.
- **No N+1**: All math happens in PHP from one or two query results. No per-brand sub-queries.
- **Bounded windows**: Max window is hardcoded to 90 days. YTD is capped by Jan 1 of the current year (max ~365 rows per currency × brand).
- **Cache-first**: 95%+ of requests should be served from Redis. Cold reads recompute under a 10s lock to prevent thundering-herd on cache miss.
- **Read replicas (future-proofing)**: All queries use `DB::connection('pgsql')` (the default `BaseModel` connection). When a read replica is added, pointing analytics at it is one config change — no service refactor.

### Caching
- **Key**: `analytics:commerce:affiliate:projections:v1:{professional_id}` (no window in key — projections are always "as of now").
- **TTL**: 300 seconds (5 min), configurable via `partna.commerce_analytics.projections_ttl_seconds`. Longer than the 60s on `overview` because projections are smoother — daily aggregates change at most once per minute on the busiest affiliates.
- **SWR**: Inherited from `CacheLockService::rememberLocked` (10× TTL stale window — so up to 50 min of stale-served-while-recompute on miss). Stampede protection via 10s lock + 5s block.
- **Invalidation**: `AnalyticsCacheService::invalidateAnalytics($professionalId)` is extended to also `forget` the projections key. This is called from `ProcessShopifyOrderUpdatedWebhookJob` on every order, edit, cancel, and refund. Net effect: projections refresh within ~seconds of any commerce write.
- **Versioning**: `v1` baked into the key — when you change the response shape, bump to `v2` and old cache evicts naturally.

---

## File structure

| File | Type | Responsibility |
|---|---|---|
| `app/Services/Analytics/AffiliateProjectionsService.php` | Create | All math: window selection, run-rate, momentum, projections, confidence, YTD, engagement. |
| `app/Http/Controllers/Api/Professional/Analytics/AffiliateProjectionsController.php` | Create | Thin HTTP wrapper: resolve professional, authorize, delegate to service via cache, wrap in resource. |
| `app/Http/Requests/Professional/Analytics/AffiliateProjectionsRequest.php` | Create | Validate optional `window_days` query param. |
| `app/Http/Resources/Professional/Analytics/AffiliateProjectionsResource.php` | Create | Shape the response JSON (locks the contract). |
| `app/Services/Cache/CacheKeyGenerator.php` | Modify | Add `affiliateProjections(string $professionalId): string`. |
| `app/Services/Cache/AnalyticsCacheService.php` | Modify | Extend `invalidateAnalytics()` to forget the projections key. |
| `app/Policies/CommissionPolicy.php` | Modify | Add `viewProjections(Professional $pro, BrandAffiliateRollup $skeleton): bool`. |
| `config/partna.php` | Modify | Add `commerce_analytics.projections_ttl_seconds` and `commerce_analytics.projections_window_tiers`. |
| `routes/api/professional.php` | Modify | Register `GET /affiliate/projections`. |
| `tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php` | Create | Unit-test the math in isolation. |
| `tests/Feature/Analytics/AffiliateProjectionsControllerTest.php` | Create | HTTP-level tests: insufficient_data, happy path, auth, cache invalidation. |

---

### Task 1: Add config keys

**Files:**
- Modify: `config/partna.php` — append within an existing top-level array section (likely a `commerce_analytics` block; create one if absent)

- [ ] **Step 1: Open `config/partna.php` and locate a sensible insertion point**

Look for an existing analytics-related block (e.g., `'analytics_raw_event_retention_days'`). Add a new top-level `'commerce_analytics' => [...]` array adjacent to it. If the key already exists, merge into it.

- [ ] **Step 2: Add the config block**

```php
'commerce_analytics' => [
    // SWR cache TTL (seconds) for the affiliate projections endpoint. Push-invalidated on every commerce
    // webhook write via AnalyticsCacheService::invalidateAnalytics(), so this is the upper bound on staleness
    // when no writes happen — not the typical staleness.
    'projections_ttl_seconds' => (int) env('COMMERCE_PROJECTIONS_TTL_SECONDS', 300),

    // Adaptive window tiers, descending. Service picks the largest tier the affiliate has ≥ days of history for.
    // Below the smallest tier, response returns status=insufficient_data.
    'projections_window_tiers' => [90, 60, 30, 14],

    // Confidence band thresholds. CV = stddev / mean of daily net-commission within the window.
    'projections_confidence_high' => ['min_history_days' => 90, 'max_cv' => 0.5],
    'projections_confidence_medium' => ['min_history_days' => 30, 'max_cv' => 1.0],
    // Anything qualifying for the window but not above is "low".
],
```

- [ ] **Step 3: Verify config loads**

Run: `php artisan config:clear && php artisan tinker --execute="echo json_encode(config('partna.commerce_analytics'));"`
Expected: JSON with all four keys present.

- [ ] **Step 4: Commit**

```bash
git add config/partna.php
git commit -m "feat(analytics): add config keys for affiliate projections endpoint"
```

---

### Task 2: Add cache key generator method

**Files:**
- Modify: `app/Services/Cache/CacheKeyGenerator.php`
- Test: `tests/Unit/Services/Cache/CacheKeyGeneratorTest.php` (create if missing; otherwise append)

- [ ] **Step 1: Write the failing test**

Append to (or create) `tests/Unit/Services/Cache/CacheKeyGeneratorTest.php`:

```php
<?php

use App\Services\Cache\CacheKeyGenerator;

it('builds a stable, professional-scoped cache key for affiliate projections', function () {
    $key = CacheKeyGenerator::affiliateProjections('11111111-2222-3333-4444-555555555555');
    expect($key)->toBe('analytics:commerce:affiliate:projections:v1:11111111-2222-3333-4444-555555555555');
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Services/Cache/CacheKeyGeneratorTest.php --filter="builds a stable" -v`
Expected: FAIL with "method affiliateProjections does not exist".

- [ ] **Step 3: Add the method**

In `app/Services/Cache/CacheKeyGenerator.php`, near the existing `affiliateCommerceAnalytics` method:

```php
/**
 * Cache key for the per-professional affiliate projections payload.
 * No date-range component — projections are always computed "as of now".
 * Bump v1 → v2 if the response shape changes (forces eviction of stale entries).
 */
public static function affiliateProjections(string $professionalId): string
{
    return "analytics:commerce:affiliate:projections:v1:{$professionalId}";
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Services/Cache/CacheKeyGeneratorTest.php --filter="builds a stable" -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cache/CacheKeyGenerator.php tests/Unit/Services/Cache/CacheKeyGeneratorTest.php
git commit -m "feat(cache): add affiliateProjections key generator"
```

---

### Task 3: Service skeleton + window selection

**Files:**
- Create: `app/Services/Analytics/AffiliateProjectionsService.php`
- Test: `tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php`:

```php
<?php

use App\Models\Core\Professional;
use App\Services\Analytics\AffiliateProjectionsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->service = app(AffiliateProjectionsService::class);
    $this->professional = new Professional([
        'id' => (string) Str::uuid(),
        'timezone' => 'America/New_York',
    ]);
});

it('returns insufficient_data when the affiliate has < 14 days of history', function () {
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn(now()->subDays(5)->toDateString());
    $rollupMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')
        ->with('commerce.brand_affiliate_rollup')
        ->andReturn($rollupMock);

    $result = $this->service->build($this->professional);

    expect($result['status'])->toBe('insufficient_data');
    expect($result['data_history_days'])->toBe(5);
    expect($result['by_currency'])->toBe([]);
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php -v`
Expected: FAIL with "Class App\Services\Analytics\AffiliateProjectionsService not found".

- [ ] **Step 3: Create the service skeleton**

Create `app/Services/Analytics/AffiliateProjectionsService.php`:

```php
<?php

namespace App\Services\Analytics;

use App\Models\Core\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the affiliate projections payload (run-rate, momentum, year-end forecast,
 * YTD, best month, engagement). Pure read path — no writes, no side effects.
 *
 * All math runs from `commerce.brand_affiliate_rollup`, the trigger-maintained
 * per-(day, brand, affiliate, currency) aggregation table. We never query
 * `commerce.orders` directly: the rollup is the public read interface.
 *
 * @return array{
 *   as_of: string,
 *   data_history_days: int,
 *   status: 'ok'|'insufficient_data',
 *   window: array{days:int, from:string, to:string}|null,
 *   engagement: array{earning_days_count:int, active_brand_count:int},
 *   by_currency: array<int, array<string, mixed>>
 * }
 */
class AffiliateProjectionsService
{
    public function build(Professional $professional): array
    {
        $tz = $professional->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);

        $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);

        if ($dataHistoryDays < $this->minTier()) {
            return [
                'as_of' => $now->toIso8601String(),
                'data_history_days' => $dataHistoryDays,
                'status' => 'insufficient_data',
                'window' => null,
                'engagement' => ['earning_days_count' => 0, 'active_brand_count' => 0],
                'by_currency' => [],
            ];
        }

        // Subsequent tasks fill in the happy path. For now, return a placeholder so the
        // skeleton compiles. We override this in Task 4.
        return [
            'as_of' => $now->toIso8601String(),
            'data_history_days' => $dataHistoryDays,
            'status' => 'ok',
            'window' => null,
            'engagement' => ['earning_days_count' => 0, 'active_brand_count' => 0],
            'by_currency' => [],
        ];
    }

    /**
     * Days between today (in pro's timezone) and the affiliate's earliest rollup day.
     * Returns 0 if no rollup rows exist for this affiliate.
     */
    private function resolveDataHistoryDays(string $affiliateId, CarbonImmutable $now): int
    {
        $earliest = DB::table('commerce.brand_affiliate_rollup')
            ->where('affiliate_professional_id', $affiliateId)
            ->orderBy('day', 'asc')
            ->value('day');

        if ($earliest === null) {
            return 0;
        }

        return (int) CarbonImmutable::parse($earliest)->diffInDays($now->startOfDay());
    }

    /** Smallest configured window tier — below this we return insufficient_data. */
    private function minTier(): int
    {
        $tiers = config('partna.commerce_analytics.projections_window_tiers', [90, 60, 30, 14]);
        return (int) min($tiers);
    }

    /**
     * Pick the largest tier the affiliate has ≥ days of history for.
     * Returns null if below the smallest tier.
     */
    private function selectWindowDays(int $dataHistoryDays): ?int
    {
        $tiers = config('partna.commerce_analytics.projections_window_tiers', [90, 60, 30, 14]);
        rsort($tiers);
        foreach ($tiers as $tier) {
            if ($dataHistoryDays >= $tier) {
                return (int) $tier;
            }
        }
        return null;
    }
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/AffiliateProjectionsService.php tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php
git commit -m "feat(analytics): scaffold AffiliateProjectionsService with window-tier selection"
```

---

### Task 4: Window query, run-rate, and projections (per currency)

**Files:**
- Modify: `app/Services/Analytics/AffiliateProjectionsService.php`
- Test: `tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append to the test file:

```php
it('computes run-rate and annual projections from the window rollup rows', function () {
    $today = CarbonImmutable::now('America/New_York')->startOfDay();
    $earliest = $today->subDays(60)->toDateString();
    $windowFrom = $today->subDays(29)->toDateString();
    $windowTo = $today->subDay()->toDateString();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($earliest);
    // 30 days × 100_000 net cents/day = 3_000_000 in window. 30 orders.
    $rollupMock->shouldReceive('get')->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 2,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));

    DB::shouldReceive('table')
        ->with('commerce.brand_affiliate_rollup')
        ->andReturn($rollupMock);

    $pro = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'America/New_York']);
    $result = $this->service->build($pro);

    expect($result['status'])->toBe('ok');
    expect($result['window']['days'])->toBe(30);
    expect($result['by_currency'][0]['currency_code'])->toBe('USD');
    expect($result['by_currency'][0]['run_rate']['commission_cents_per_day'])->toBe(100000);
    expect($result['by_currency'][0]['projections']['annual_commission_cents'])->toBe(36500000); // 100k * 365
    expect($result['by_currency'][0]['projections']['annual_orders'])->toBe(365); // (30/30)*365
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php --filter="computes run-rate" -v`
Expected: FAIL.

- [ ] **Step 3: Implement window query + projections**

Replace `build()` and add helpers in `app/Services/Analytics/AffiliateProjectionsService.php`:

```php
public function build(Professional $professional): array
{
    $tz = $professional->timezone ?: 'UTC';
    $now = CarbonImmutable::now($tz);

    $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);
    $windowDays = $this->selectWindowDays($dataHistoryDays);

    if ($windowDays === null) {
        return [
            'as_of' => $now->toIso8601String(),
            'data_history_days' => $dataHistoryDays,
            'status' => 'insufficient_data',
            'window' => null,
            'engagement' => ['earning_days_count' => 0, 'active_brand_count' => 0],
            'by_currency' => [],
        ];
    }

    // The window is the most recent N complete days *up to and including yesterday* in pro's TZ.
    // Today is in-flight, so excluding it avoids dragging the rate down with a half-day.
    $windowTo = $now->subDay()->startOfDay();
    $windowFrom = $windowTo->subDays($windowDays - 1);

    $perCurrency = $this->fetchPerCurrencyAggregates(
        $professional->id,
        $windowFrom->toDateString(),
        $windowTo->toDateString(),
        $windowDays
    );

    // Engagement is currency-agnostic: max across rows.
    $engagement = [
        'earning_days_count' => (int) ($perCurrency->max('earning_days') ?? 0),
        'active_brand_count' => (int) ($perCurrency->max('brand_count') ?? 0),
    ];

    $byCurrency = $perCurrency
        ->sortByDesc('window_net_cents')
        ->values()
        ->map(fn ($row) => $this->buildCurrencyEntry($row, $windowDays, $now))
        ->all();

    return [
        'as_of' => $now->toIso8601String(),
        'data_history_days' => $dataHistoryDays,
        'status' => 'ok',
        'window' => [
            'days' => $windowDays,
            'from' => $windowFrom->toDateString(),
            'to' => $windowTo->toDateString(),
        ],
        'engagement' => $engagement,
        'by_currency' => $byCurrency,
    ];
}

/**
 * One SQL round-trip aggregating window stats per currency. Returns a Collection
 * of stdClass with keys: currency_code, window_net_cents, window_orders, earning_days,
 * brand_count, daily_values_json (JSON-encoded array of per-day net cents, length=window_days).
 */
private function fetchPerCurrencyAggregates(
    string $affiliateId,
    string $from,
    string $to,
    int $windowDays,
): \Illuminate\Support\Collection {
    return DB::table('commerce.brand_affiliate_rollup')
        ->where('affiliate_professional_id', $affiliateId)
        ->whereBetween('day', [$from, $to])
        ->groupBy('currency_code')
        ->selectRaw('
            currency_code,
            COALESCE(SUM(commission_cents - reversed_commission_cents), 0) AS window_net_cents,
            COALESCE(SUM(orders_count), 0) AS window_orders,
            COUNT(DISTINCT day) FILTER (WHERE (commission_cents - reversed_commission_cents) > 0) AS earning_days,
            COUNT(DISTINCT brand_professional_id) FILTER (WHERE (commission_cents - reversed_commission_cents) > 0) AS brand_count,
            COALESCE(
                jsonb_agg(commission_cents - reversed_commission_cents ORDER BY day),
                \'[]\'::jsonb
            )::text AS daily_values_json
        ')
        ->get();
}

private function buildCurrencyEntry(object $row, int $windowDays, CarbonImmutable $now): array
{
    $netCents = (int) $row->window_net_cents;
    $orders = (int) $row->window_orders;

    $runRateCentsPerDay = (int) round($netCents / $windowDays);
    $ordersPerDay = round($orders / $windowDays, 2);

    $annualCommission = (int) round($runRateCentsPerDay * 365);
    $annualOrders = (int) round($ordersPerDay * 365);

    return [
        'currency_code' => (string) $row->currency_code,
        'run_rate' => [
            'commission_cents_per_day' => $runRateCentsPerDay,
            'orders_per_day' => $ordersPerDay,
        ],
        'projections' => [
            'annual_commission_cents' => $annualCommission,
            'year_end_commission_cents' => 0, // Task 7 fills this in
            'annual_orders' => $annualOrders,
            'confidence' => 'low', // Task 6 fills this in
        ],
        'momentum' => null, // Task 5 fills this in
        'ytd' => null, // Task 7 fills this in
    ];
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php -v`
Expected: PASS for both tests so far.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/AffiliateProjectionsService.php tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php
git commit -m "feat(analytics): compute run-rate and annual projections per currency"
```

---

### Task 5: Momentum (window vs prior-window)

**Files:**
- Modify: `app/Services/Analytics/AffiliateProjectionsService.php`
- Test: `tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append:

```php
it('computes momentum as pct change from prior window run-rate', function () {
    $today = CarbonImmutable::now('America/New_York')->startOfDay();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($today->subDays(120)->toDateString());

    // Two get() calls: window and prior-window. Use ordered shouldReceive.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 2,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    // Prior window: 50% lower run-rate (50k/day instead of 100k/day).
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'prior_net_cents' => 1_500_000],
    ]));

    DB::shouldReceive('table')
        ->with('commerce.brand_affiliate_rollup')
        ->andReturn($rollupMock);

    $pro = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'America/New_York']);
    $result = $this->service->build($pro);

    expect($result['by_currency'][0]['momentum']['pct_change_vs_prior_window'])->toBe(1.0); // 100k vs 50k = +100%
    expect($result['by_currency'][0]['momentum']['prior_run_rate_cents_per_day'])->toBe(50000);
});

it('returns null pct_change when prior-window run-rate is zero (avoid div-by-zero)', function () {
    $today = CarbonImmutable::now('America/New_York')->startOfDay();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($today->subDays(60)->toDateString());

    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 2,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // no prior data

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    $pro = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'America/New_York']);
    $result = $this->service->build($pro);

    expect($result['by_currency'][0]['momentum']['pct_change_vs_prior_window'])->toBeNull();
    expect($result['by_currency'][0]['momentum']['prior_run_rate_cents_per_day'])->toBe(0);
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php --filter="momentum" -v`
Expected: FAIL.

- [ ] **Step 3: Implement momentum**

In `AffiliateProjectionsService::build()`, after the `fetchPerCurrencyAggregates` call, add:

```php
$priorWindowTo = $windowFrom->subDay();
$priorWindowFrom = $priorWindowTo->subDays($windowDays - 1);
$priorByCurrency = $this->fetchPriorWindowAggregates(
    $professional->id,
    $priorWindowFrom->toDateString(),
    $priorWindowTo->toDateString()
)->keyBy('currency_code');
```

Then update `buildCurrencyEntry` to take a `$priorByCurrency` argument and add momentum:

```php
private function buildCurrencyEntry(
    object $row,
    int $windowDays,
    CarbonImmutable $now,
    \Illuminate\Support\Collection $priorByCurrency,
): array {
    $netCents = (int) $row->window_net_cents;
    $orders = (int) $row->window_orders;

    $runRateCentsPerDay = (int) round($netCents / $windowDays);
    $ordersPerDay = round($orders / $windowDays, 2);

    $annualCommission = (int) round($runRateCentsPerDay * 365);
    $annualOrders = (int) round($ordersPerDay * 365);

    $priorRow = $priorByCurrency->get($row->currency_code);
    $priorNet = $priorRow ? (int) $priorRow->prior_net_cents : 0;
    $priorRunRate = (int) round($priorNet / $windowDays);
    $pctChange = $priorRunRate > 0
        ? round(($runRateCentsPerDay - $priorRunRate) / $priorRunRate, 4)
        : null;

    return [
        'currency_code' => (string) $row->currency_code,
        'run_rate' => [
            'commission_cents_per_day' => $runRateCentsPerDay,
            'orders_per_day' => $ordersPerDay,
        ],
        'projections' => [
            'annual_commission_cents' => $annualCommission,
            'year_end_commission_cents' => 0,
            'annual_orders' => $annualOrders,
            'confidence' => 'low',
        ],
        'momentum' => [
            'pct_change_vs_prior_window' => $pctChange,
            'prior_run_rate_cents_per_day' => $priorRunRate,
        ],
        'ytd' => null,
    ];
}

private function fetchPriorWindowAggregates(
    string $affiliateId,
    string $from,
    string $to,
): \Illuminate\Support\Collection {
    return DB::table('commerce.brand_affiliate_rollup')
        ->where('affiliate_professional_id', $affiliateId)
        ->whereBetween('day', [$from, $to])
        ->groupBy('currency_code')
        ->selectRaw('
            currency_code,
            COALESCE(SUM(commission_cents - reversed_commission_cents), 0) AS prior_net_cents
        ')
        ->get();
}
```

Update the `map(...)` call inside `build()` to pass `$priorByCurrency`:

```php
$byCurrency = $perCurrency
    ->sortByDesc('window_net_cents')
    ->values()
    ->map(fn ($row) => $this->buildCurrencyEntry($row, $windowDays, $now, $priorByCurrency))
    ->all();
```

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php -v`
Expected: PASS for all tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/AffiliateProjectionsService.php tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php
git commit -m "feat(analytics): add momentum (window vs prior-window run-rate)"
```

---

### Task 6: Confidence band

**Files:**
- Modify: `app/Services/Analytics/AffiliateProjectionsService.php`
- Test: `tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php`

Confidence band is determined by data history length AND the coefficient of variation (CV = stddev/mean) of daily net commission. The `daily_values_json` column from the window query holds the per-day values.

- [ ] **Step 1: Write the failing test**

```php
it('returns high confidence when history >= 90d AND CV < 0.5', function () {
    expectConfidence(historyDays: 95, dailyValues: array_fill(0, 90, 100000), expected: 'high');
});

it('returns medium confidence when history >= 30d AND CV < 1.0 but high not met', function () {
    // 30 days, mostly 100k with one 200k spike → CV ≈ 0.18 → medium (< 90 day history blocks high)
    $values = array_merge(array_fill(0, 29, 100000), [200000]);
    expectConfidence(historyDays: 35, dailyValues: $values, expected: 'medium');
});

it('returns low confidence when CV is high', function () {
    // Volatile: lots of zeros and a few big days → CV > 1.0
    $values = array_merge(array_fill(0, 27, 0), [500000, 500000, 500000]);
    expectConfidence(historyDays: 95, dailyValues: $values, expected: 'low');
});

// Helper that wires up DB mocks for a single-currency window with given daily values.
function expectConfidence(int $historyDays, array $dailyValues, string $expected): void
{
    $today = CarbonImmutable::now('America/New_York')->startOfDay();
    $windowDays = count($dailyValues);
    $netCents = array_sum($dailyValues);

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($today->subDays($historyDays)->toDateString());
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => $netCents,
            'window_orders' => $windowDays,
            'earning_days' => count(array_filter($dailyValues, fn ($v) => $v > 0)),
            'brand_count' => 1,
            'daily_values_json' => json_encode($dailyValues),
        ],
    ]));
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // no prior

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    $service = app(\App\Services\Analytics\AffiliateProjectionsService::class);
    $pro = new \App\Models\Core\Professional(['id' => (string) \Illuminate\Support\Str::uuid(), 'timezone' => 'America/New_York']);
    $result = $service->build($pro);

    expect($result['by_currency'][0]['projections']['confidence'])->toBe($expected);
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php --filter="confidence" -v`
Expected: FAIL — current code returns `'low'` for everything.

- [ ] **Step 3: Implement confidence**

In `AffiliateProjectionsService`, replace the hardcoded `'confidence' => 'low'` with a call to a new `confidenceBand()` method, passing the data history days and daily values:

```php
// In buildCurrencyEntry, replace 'confidence' line:
'confidence' => $this->confidenceBand($dataHistoryDays, $row->daily_values_json),
```

Update `buildCurrencyEntry` signature to accept `$dataHistoryDays`, and add the helper:

```php
/**
 * High: ≥90d history AND CV < 0.5.
 * Medium: ≥30d history AND CV < 1.0 (or ≥30d with CV unmeasurable).
 * Low: anything else qualifying for the smallest tier.
 *
 * CV is computed from the JSON array of daily net cents. If the JSON is malformed
 * or the array is empty, we fall back to 'low' rather than throwing — this is a
 * degraded-but-honest signal, not a hard failure.
 */
private function confidenceBand(int $dataHistoryDays, ?string $dailyValuesJson): string
{
    $high = config('partna.commerce_analytics.projections_confidence_high');
    $medium = config('partna.commerce_analytics.projections_confidence_medium');

    $cv = $this->coefficientOfVariation($dailyValuesJson);

    if ($dataHistoryDays >= ($high['min_history_days'] ?? 90)
        && $cv !== null
        && $cv < ($high['max_cv'] ?? 0.5)
    ) {
        return 'high';
    }
    if ($dataHistoryDays >= ($medium['min_history_days'] ?? 30)
        && ($cv === null || $cv < ($medium['max_cv'] ?? 1.0))
    ) {
        return 'medium';
    }
    return 'low';
}

/**
 * Population coefficient of variation (stddev / mean).
 * Returns null when mean is 0 (CV undefined) or values are missing.
 */
private function coefficientOfVariation(?string $dailyValuesJson): ?float
{
    if ($dailyValuesJson === null || $dailyValuesJson === '') {
        return null;
    }
    $values = json_decode($dailyValuesJson, true);
    if (!is_array($values) || count($values) === 0) {
        return null;
    }
    $n = count($values);
    $mean = array_sum($values) / $n;
    if ($mean <= 0) {
        return null;
    }
    $variance = 0.0;
    foreach ($values as $v) {
        $variance += (((float) $v) - $mean) ** 2;
    }
    $variance /= $n;
    return sqrt($variance) / $mean;
}
```

Pass `$dataHistoryDays` through to `buildCurrencyEntry`. Update the `build()` map call:

```php
$byCurrency = $perCurrency
    ->sortByDesc('window_net_cents')
    ->values()
    ->map(fn ($row) => $this->buildCurrencyEntry($row, $windowDays, $now, $priorByCurrency, $dataHistoryDays))
    ->all();
```

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php -v`
Expected: PASS for all tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/AffiliateProjectionsService.php tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php
git commit -m "feat(analytics): add confidence band based on history + CV"
```

---

### Task 7: YTD totals + best month + year-end projection

**Files:**
- Modify: `app/Services/Analytics/AffiliateProjectionsService.php`
- Test: `tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('computes YTD totals, best month, and year-end projection per currency', function () {
    $now = CarbonImmutable::now('America/New_York');
    $today = $now->startOfDay();

    // Window mock (already covered above).
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($today->subDays(120)->toDateString());
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,    // 100k/day
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 2,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // prior empty
    // YTD aggregate.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'ytd_net_cents' => 6_120_000,   // YTD total
            'ytd_orders' => 178,
        ],
    ]));
    // Best month per currency.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'best_month' => '2026-03',
            'best_month_net_cents' => 1_840_000,
        ],
    ]));

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    $pro = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'America/New_York']);
    $result = $this->service->build($pro);

    $usd = $result['by_currency'][0];
    expect($usd['ytd']['commission_cents'])->toBe(6120000);
    expect($usd['ytd']['orders_count'])->toBe(178);
    expect($usd['ytd']['best_month'])->toBe('2026-03');
    expect($usd['ytd']['best_month_commission_cents'])->toBe(1840000);

    // year_end = ytd + (run_rate * days_remaining_in_year)
    $daysRemaining = $now->endOfYear()->startOfDay()->diffInDays($today);
    expect($usd['projections']['year_end_commission_cents'])
        ->toBe(6120000 + 100000 * $daysRemaining);
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php --filter="YTD" -v`
Expected: FAIL.

- [ ] **Step 3: Implement YTD + best-month + year-end**

Add two new fetch methods and wire them into `build()`:

```php
// Add after fetchPriorWindowAggregates:

private function fetchYtdAggregates(
    string $affiliateId,
    string $yearStart,
): \Illuminate\Support\Collection {
    return DB::table('commerce.brand_affiliate_rollup')
        ->where('affiliate_professional_id', $affiliateId)
        ->where('day', '>=', $yearStart)
        ->groupBy('currency_code')
        ->selectRaw('
            currency_code,
            COALESCE(SUM(commission_cents - reversed_commission_cents), 0) AS ytd_net_cents,
            COALESCE(SUM(orders_count), 0) AS ytd_orders
        ')
        ->get();
}

private function fetchBestMonthPerCurrency(
    string $affiliateId,
    string $yearStart,
): \Illuminate\Support\Collection {
    // Per currency, return the month-of-year with the highest net commission.
    // DISTINCT ON is Postgres-specific; the rollup table only ever lives on Postgres,
    // so this is safe.
    return DB::table('commerce.brand_affiliate_rollup')
        ->where('affiliate_professional_id', $affiliateId)
        ->where('day', '>=', $yearStart)
        ->selectRaw("
            DISTINCT ON (currency_code)
            currency_code,
            to_char(day, 'YYYY-MM') AS best_month,
            month_net AS best_month_net_cents
        ")
        ->fromRaw("(
            SELECT
                currency_code,
                date_trunc('month', day) AS month_start,
                day,
                SUM(commission_cents - reversed_commission_cents) OVER (
                    PARTITION BY currency_code, date_trunc('month', day)
                ) AS month_net
            FROM commerce.brand_affiliate_rollup
            WHERE affiliate_professional_id = ?
              AND day >= ?
        ) AS monthly", [$affiliateId, $yearStart])
        ->orderByRaw('currency_code, month_net DESC')
        ->get();
}
```

> ⚠️ **Note on the `fromRaw` query above**: Laravel's query builder `fromRaw` accepts bindings via the second arg. The outer `where('affiliate_professional_id', ...)` you'd normally use is replaced here by the bindings inside the subquery — make sure the engineer copies the parameter list exactly. We are NOT chaining `where()` to the outer query here because the subquery is the canonical source.

Then in `build()`, after computing `priorByCurrency`:

```php
$yearStart = $now->startOfYear()->toDateString();
$ytdByCurrency = $this->fetchYtdAggregates($professional->id, $yearStart)->keyBy('currency_code');
$bestMonthByCurrency = $this->fetchBestMonthPerCurrency($professional->id, $yearStart)->keyBy('currency_code');

$daysRemainingInYear = (int) $now->endOfYear()->startOfDay()->diffInDays($now->startOfDay());
```

Update `buildCurrencyEntry` signature again to accept `$ytdByCurrency`, `$bestMonthByCurrency`, `$daysRemainingInYear`. Replace the placeholder `'ytd' => null` and `'year_end_commission_cents' => 0`:

```php
private function buildCurrencyEntry(
    object $row,
    int $windowDays,
    CarbonImmutable $now,
    \Illuminate\Support\Collection $priorByCurrency,
    int $dataHistoryDays,
    \Illuminate\Support\Collection $ytdByCurrency,
    \Illuminate\Support\Collection $bestMonthByCurrency,
    int $daysRemainingInYear,
): array {
    $netCents = (int) $row->window_net_cents;
    $orders = (int) $row->window_orders;

    $runRateCentsPerDay = (int) round($netCents / $windowDays);
    $ordersPerDay = round($orders / $windowDays, 2);

    $annualCommission = (int) round($runRateCentsPerDay * 365);
    $annualOrders = (int) round($ordersPerDay * 365);

    $priorRow = $priorByCurrency->get($row->currency_code);
    $priorNet = $priorRow ? (int) $priorRow->prior_net_cents : 0;
    $priorRunRate = (int) round($priorNet / $windowDays);
    $pctChange = $priorRunRate > 0
        ? round(($runRateCentsPerDay - $priorRunRate) / $priorRunRate, 4)
        : null;

    $ytdRow = $ytdByCurrency->get($row->currency_code);
    $ytdNet = $ytdRow ? (int) $ytdRow->ytd_net_cents : 0;
    $ytdOrders = $ytdRow ? (int) $ytdRow->ytd_orders : 0;

    $bestMonthRow = $bestMonthByCurrency->get($row->currency_code);
    $bestMonth = $bestMonthRow ? (string) $bestMonthRow->best_month : null;
    $bestMonthNet = $bestMonthRow ? (int) $bestMonthRow->best_month_net_cents : 0;

    $yearEndCommission = (int) ($ytdNet + ($runRateCentsPerDay * $daysRemainingInYear));

    return [
        'currency_code' => (string) $row->currency_code,
        'run_rate' => [
            'commission_cents_per_day' => $runRateCentsPerDay,
            'orders_per_day' => $ordersPerDay,
        ],
        'projections' => [
            'annual_commission_cents' => $annualCommission,
            'year_end_commission_cents' => $yearEndCommission,
            'annual_orders' => $annualOrders,
            'confidence' => $this->confidenceBand($dataHistoryDays, $row->daily_values_json),
        ],
        'momentum' => [
            'pct_change_vs_prior_window' => $pctChange,
            'prior_run_rate_cents_per_day' => $priorRunRate,
        ],
        'ytd' => [
            'commission_cents' => $ytdNet,
            'orders_count' => $ytdOrders,
            'best_month' => $bestMonth,
            'best_month_commission_cents' => $bestMonthNet,
        ],
    ];
}
```

Update the `map(...)` call in `build()` to pass all required args.

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php -v`
Expected: PASS for all tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/AffiliateProjectionsService.php tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php
git commit -m "feat(analytics): add YTD totals, best month, and year-end projection"
```

---

### Task 8: Form Request

**Files:**
- Create: `app/Http/Requests/Professional/Analytics/AffiliateProjectionsRequest.php`
- Test: `tests/Unit/Http/Requests/AffiliateProjectionsRequestTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Requests\Professional\Analytics\AffiliateProjectionsRequest;
use Illuminate\Support\Facades\Validator;

it('accepts no params (defaults to adaptive window)', function () {
    $rules = (new AffiliateProjectionsRequest())->rules();
    $v = Validator::make([], $rules);
    expect($v->passes())->toBeTrue();
});

it('accepts window_days from the allowlist', function () {
    $rules = (new AffiliateProjectionsRequest())->rules();
    foreach ([14, 30, 60, 90] as $days) {
        $v = Validator::make(['window_days' => $days], $rules);
        expect($v->passes())->toBeTrue();
    }
});

it('rejects window_days outside the allowlist', function () {
    $rules = (new AffiliateProjectionsRequest())->rules();
    foreach ([0, 7, 365, -1, 'abc'] as $bad) {
        $v = Validator::make(['window_days' => $bad], $rules);
        expect($v->passes())->toBeFalse();
    }
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Http/Requests/AffiliateProjectionsRequestTest.php -v`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create the Form Request**

```php
<?php

namespace App\Http\Requests\Professional\Analytics;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates query params for GET /api/professional/affiliate/projections.
 *
 * `window_days` is optional. When omitted, the service picks the largest tier
 * the affiliate has enough history for (90 → 60 → 30 → 14). When provided, it
 * must be one of the allowed tier sizes — anything else is rejected with 422
 * to prevent unbounded windows from hitting the rollup table.
 */
class AffiliateProjectionsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        // Auth gate is handled by the route middleware (auth.professional).
        // Resource-level authorization is performed in the controller via
        // CommissionPolicy::viewProjections.
        return true;
    }

    public function rules(): array
    {
        return [
            'window_days' => ['sometimes', 'integer', 'in:14,30,60,90'],
        ];
    }

    public function messages(): array
    {
        return [
            'window_days.in' => 'window_days must be one of 14, 30, 60, or 90.',
        ];
    }
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Http/Requests/AffiliateProjectionsRequestTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Professional/Analytics/AffiliateProjectionsRequest.php tests/Unit/Http/Requests/AffiliateProjectionsRequestTest.php
git commit -m "feat(analytics): add AffiliateProjectionsRequest with window allowlist"
```

---

### Task 9: Resource class

**Files:**
- Create: `app/Http/Resources/Professional/Analytics/AffiliateProjectionsResource.php`
- Test: `tests/Unit/Http/Resources/AffiliateProjectionsResourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Resources\Professional\Analytics\AffiliateProjectionsResource;
use Illuminate\Http\Request;

it('shapes the happy-path payload exactly per the API contract', function () {
    $payload = [
        'as_of' => '2026-05-07T14:23:11+00:00',
        'data_history_days' => 47,
        'status' => 'ok',
        'window' => ['days' => 30, 'from' => '2026-04-07', 'to' => '2026-05-06'],
        'engagement' => ['earning_days_count' => 22, 'active_brand_count' => 2],
        'by_currency' => [
            [
                'currency_code' => 'USD',
                'run_rate' => ['commission_cents_per_day' => 4231, 'orders_per_day' => 1.2],
                'projections' => [
                    'annual_commission_cents' => 1544315,
                    'year_end_commission_cents' => 1102000,
                    'annual_orders' => 438,
                    'confidence' => 'medium',
                ],
                'momentum' => ['pct_change_vs_prior_window' => 0.23, 'prior_run_rate_cents_per_day' => 3440],
                'ytd' => [
                    'commission_cents' => 612000,
                    'orders_count' => 178,
                    'best_month' => '2026-03',
                    'best_month_commission_cents' => 184000,
                ],
            ],
        ],
    ];

    $array = (new AffiliateProjectionsResource($payload))->toArray(Request::create('/'));

    expect($array['status'])->toBe('ok');
    expect($array['by_currency'][0]['projections']['annual_commission_cents'])->toBe(1544315);
    expect($array['by_currency'][0]['projections']['confidence'])->toBe('medium');
    expect($array)->toHaveKeys(['as_of', 'data_history_days', 'status', 'window', 'engagement', 'by_currency']);
});

it('shapes the insufficient_data payload', function () {
    $payload = [
        'as_of' => '2026-05-07T14:23:11+00:00',
        'data_history_days' => 5,
        'status' => 'insufficient_data',
        'window' => null,
        'engagement' => ['earning_days_count' => 5, 'active_brand_count' => 1],
        'by_currency' => [],
    ];

    $array = (new AffiliateProjectionsResource($payload))->toArray(Request::create('/'));

    expect($array['status'])->toBe('insufficient_data');
    expect($array['window'])->toBeNull();
    expect($array['by_currency'])->toBe([]);
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Http/Resources/AffiliateProjectionsResourceTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create the Resource**

```php
<?php

namespace App\Http\Resources\Professional\Analytics;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Locks the wire format for GET /affiliate/projections.
 *
 * Output discipline:
 *   - All money fields end in `_cents` and cast to int.
 *   - Counts cast to int. Per-day rates cast to float (rounded upstream to 2dp).
 *   - status ∈ {'ok', 'insufficient_data'}; when 'insufficient_data', `window` is null
 *     and `by_currency` is an empty array.
 *   - `momentum.pct_change_vs_prior_window` may be null when the prior-window run-rate is 0.
 *   - `ytd.best_month` may be null when no earnings exist YTD; partner field is 0 in that case.
 */
class AffiliateProjectionsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'as_of' => (string) ($r['as_of'] ?? ''),
            'data_history_days' => (int) ($r['data_history_days'] ?? 0),
            'status' => (string) ($r['status'] ?? 'insufficient_data'),
            'window' => $r['window'] === null ? null : [
                'days' => (int) $r['window']['days'],
                'from' => (string) $r['window']['from'],
                'to' => (string) $r['window']['to'],
            ],
            'engagement' => [
                'earning_days_count' => (int) ($r['engagement']['earning_days_count'] ?? 0),
                'active_brand_count' => (int) ($r['engagement']['active_brand_count'] ?? 0),
            ],
            'by_currency' => array_map(
                fn (array $c) => $this->shapeCurrency($c),
                $r['by_currency'] ?? []
            ),
        ];
    }

    private function shapeCurrency(array $c): array
    {
        $pct = $c['momentum']['pct_change_vs_prior_window'] ?? null;
        return [
            'currency_code' => (string) $c['currency_code'],
            'run_rate' => [
                'commission_cents_per_day' => (int) $c['run_rate']['commission_cents_per_day'],
                'orders_per_day' => (float) $c['run_rate']['orders_per_day'],
            ],
            'projections' => [
                'annual_commission_cents' => (int) $c['projections']['annual_commission_cents'],
                'year_end_commission_cents' => (int) $c['projections']['year_end_commission_cents'],
                'annual_orders' => (int) $c['projections']['annual_orders'],
                'confidence' => (string) $c['projections']['confidence'],
            ],
            'momentum' => [
                'pct_change_vs_prior_window' => $pct === null ? null : (float) $pct,
                'prior_run_rate_cents_per_day' => (int) $c['momentum']['prior_run_rate_cents_per_day'],
            ],
            'ytd' => [
                'commission_cents' => (int) $c['ytd']['commission_cents'],
                'orders_count' => (int) $c['ytd']['orders_count'],
                'best_month' => $c['ytd']['best_month'] === null ? null : (string) $c['ytd']['best_month'],
                'best_month_commission_cents' => (int) $c['ytd']['best_month_commission_cents'],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Http/Resources/AffiliateProjectionsResourceTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/Professional/Analytics/AffiliateProjectionsResource.php tests/Unit/Http/Resources/AffiliateProjectionsResourceTest.php
git commit -m "feat(analytics): add AffiliateProjectionsResource locking the wire format"
```

---

### Task 10: Add `viewProjections` ability to CommissionPolicy

**Files:**
- Modify: `app/Policies/CommissionPolicy.php`
- Test: `tests/Unit/Policies/CommissionPolicyTest.php` (create or append)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Commerce\BrandAffiliateRollup;
use App\Models\Core\Professional;
use App\Policies\CommissionPolicy;

it('allows a professional to view their own projections', function () {
    $pro = new Professional(['id' => '11111111-1111-1111-1111-111111111111']);
    $skeleton = new BrandAffiliateRollup(['affiliate_professional_id' => $pro->id]);
    $policy = new CommissionPolicy();

    expect($policy->viewProjections($pro, $skeleton))->toBeTrue();
});

it('denies a professional from viewing another professional projections', function () {
    $pro = new Professional(['id' => '11111111-1111-1111-1111-111111111111']);
    $skeleton = new BrandAffiliateRollup(['affiliate_professional_id' => '22222222-2222-2222-2222-222222222222']);
    $policy = new CommissionPolicy();

    expect($policy->viewProjections($pro, $skeleton))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Policies/CommissionPolicyTest.php --filter="projections" -v`
Expected: FAIL — method `viewProjections` does not exist.

- [ ] **Step 3: Add the policy method**

In `app/Policies/CommissionPolicy.php`:

```php
/**
 * Authorizes a professional to view their own affiliate projections.
 *
 * The skeleton must carry the affiliate_professional_id of the *requested* projections.
 * Defense-in-depth: RLS on commerce.brand_affiliate_rollup also blocks cross-tenant reads,
 * but we want a clean 403 at the HTTP layer rather than an empty result.
 */
public function viewProjections(Professional $pro, BrandAffiliateRollup $skeleton): bool
{
    return (string) $pro->id === (string) $skeleton->affiliate_professional_id;
}
```

Make sure the `use App\Models\Commerce\BrandAffiliateRollup;` and `use App\Models\Core\Professional;` imports are present at the top of the file.

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Policies/CommissionPolicyTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/CommissionPolicy.php tests/Unit/Policies/CommissionPolicyTest.php
git commit -m "feat(authz): add viewProjections ability to CommissionPolicy"
```

---

### Task 11: Controller

**Files:**
- Create: `app/Http/Controllers/Api/Professional/Analytics/AffiliateProjectionsController.php`

(No dedicated test yet — feature test in Task 14 covers the controller end-to-end.)

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Professional\Analytics\AffiliateProjectionsRequest;
use App\Http\Resources\Professional\Analytics\AffiliateProjectionsResource;
use App\Models\Commerce\BrandAffiliateRollup;
use App\Services\Analytics\AffiliateProjectionsService;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;

/**
 * GET /api/professional/affiliate/projections
 *
 * Returns straight-line annual + year-end forecasts, run-rate, momentum, YTD,
 * best-month, and engagement metrics for the authenticated affiliate.
 *
 * Caching: SWR via CacheLockService::rememberLocked, keyed only on professional_id
 * (not on query params — projections are absolute "as of now"). TTL is configurable
 * (`partna.commerce_analytics.projections_ttl_seconds`, default 300s). Invalidated
 * by AnalyticsCacheService::invalidateAnalytics() on every commerce write.
 *
 * Authorization: defense-in-depth — RLS on commerce.brand_affiliate_rollup blocks
 * cross-tenant reads at the DB layer, plus an explicit policy gate here for HTTP-layer 403s.
 */
class AffiliateProjectionsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly CacheLockService $cacheLock,
        private readonly AffiliateProjectionsService $projections,
    ) {}

    public function show(AffiliateProjectionsRequest $request): \Illuminate\Http\JsonResponse
    {
        $professional = $this->currentProfessional($request);

        // Defense-in-depth authorization. RLS will also block cross-tenant rollup reads,
        // but this gives a clean 403 at the HTTP edge instead of an empty 200.
        $skeleton = new BrandAffiliateRollup([
            'affiliate_professional_id' => $professional->id,
        ]);
        $this->authorizeForUser($professional, 'viewProjections', $skeleton);

        $ttl = (int) config('partna.commerce_analytics.projections_ttl_seconds', 300);
        $cacheKey = CacheKeyGenerator::affiliateProjections((string) $professional->id);

        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            $ttl,
            fn () => $this->projections->build($professional),
        );

        return $this->success(
            (new AffiliateProjectionsResource($payload))->toArray($request),
        );
    }
}
```

- [ ] **Step 2: Verify it autoloads**

Run: `php artisan route:list --path=affiliate/projections 2>&1 | head -20`
Expected: empty (route not registered yet) — but no class-not-found error from Laravel boot.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Analytics/AffiliateProjectionsController.php
git commit -m "feat(analytics): add AffiliateProjectionsController with policy-gated SWR cache"
```

---

### Task 12: Register the route

**Files:**
- Modify: `routes/api/professional.php`

- [ ] **Step 1: Locate the affiliate analytics route**

Find the existing line (per the explore report):
```php
Route::get('/affiliate/commerce-analytics', [AffiliateCommerceAnalyticsController::class, 'overview']);
```

- [ ] **Step 2: Add the projections route immediately below it**

```php
Route::get('/affiliate/projections', [
    \App\Http\Controllers\Api\Professional\Analytics\AffiliateProjectionsController::class, 'show'
])->name('professional.affiliate.projections');
```

- [ ] **Step 3: Verify route registration**

Run: `php artisan route:list --path=affiliate/projections`
Expected: one row: `GET|HEAD api/professional/affiliate/projections ... professional.affiliate.projections`.

- [ ] **Step 4: Commit**

```bash
git add routes/api/professional.php
git commit -m "feat(analytics): register affiliate projections route"
```

---

### Task 13: Wire cache invalidation

**Files:**
- Modify: `app/Services/Cache/AnalyticsCacheService.php`
- Test: `tests/Unit/Services/Cache/AnalyticsCacheServiceTest.php` (create or append)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;

it('forgets the affiliate projections cache key when invalidating', function () {
    Cache::flush();
    $proId = '11111111-1111-1111-1111-111111111111';
    $key = CacheKeyGenerator::affiliateProjections($proId);

    Cache::put($key, ['payload' => 'stale'], 600);
    expect(Cache::has($key))->toBeTrue();

    app(AnalyticsCacheService::class)->invalidateAnalytics($proId);

    expect(Cache::has($key))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `./vendor/bin/pest tests/Unit/Services/Cache/AnalyticsCacheServiceTest.php --filter="projections" -v`
Expected: FAIL — projections key still cached.

- [ ] **Step 3: Extend `invalidateAnalytics`**

In `app/Services/Cache/AnalyticsCacheService.php`, find the `invalidateAnalytics` method body. Add the projections forget alongside the existing version-bump:

```php
public function invalidateAnalytics(string $professionalId): void
{
    Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($professionalId));

    // Drop the affiliate projections cache so the next request recomputes from
    // fresh rollup state. Cheap forget; SWR layer will serve any stale entry under
    // its 10× TTL window if a recompute is in flight.
    Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId));
    Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId) . ':stale');

    // ... existing rolling-window forget logic stays as-is below.
}
```

> The `:stale` suffix is `CacheLockService`'s SWR companion key — forget both so a recompute genuinely starts from scratch on the next read.

- [ ] **Step 4: Run test to verify pass**

Run: `./vendor/bin/pest tests/Unit/Services/Cache/AnalyticsCacheServiceTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cache/AnalyticsCacheService.php tests/Unit/Services/Cache/AnalyticsCacheServiceTest.php
git commit -m "feat(cache): bust affiliate projections cache on commerce invalidation"
```

---

### Task 14: Feature test — insufficient_data path

**Files:**
- Create: `tests/Feature/Analytics/AffiliateProjectionsControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateProjectionsController;
use App\Models\Core\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->controller = app(AffiliateProjectionsController::class);
    $this->professional = new Professional([
        'id' => (string) Str::uuid(),
        'timezone' => 'UTC',
    ]);
    Cache::flush();
});

it('returns insufficient_data when the affiliate has no rollup history', function () {
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn(null); // no history at all
    $rollupMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    $request = AffiliateProjectionsRequest::create('/api/professional/affiliate/projections', 'GET');
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->show(
        \App\Http\Requests\Professional\Analytics\AffiliateProjectionsRequest::createFrom($request)
    );

    $body = json_decode($response->getContent(), true);
    expect($body['data']['status'])->toBe('insufficient_data');
    expect($body['data']['data_history_days'])->toBe(0);
    expect($body['data']['by_currency'])->toBe([]);
});
```

- [ ] **Step 2: Run test to verify failure (or to confirm it works)**

Run: `./vendor/bin/pest tests/Feature/Analytics/AffiliateProjectionsControllerTest.php --filter="insufficient" -v`
Expected: PASS — all infrastructure is now in place, this test validates the full HTTP path.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Analytics/AffiliateProjectionsControllerTest.php
git commit -m "test(analytics): feature test for insufficient_data projections path"
```

---

### Task 15: Feature test — happy path

**Files:**
- Modify: `tests/Feature/Analytics/AffiliateProjectionsControllerTest.php`

- [ ] **Step 1: Append the test**

```php
it('returns ok with full projections when the affiliate has 90+ days of stable history', function () {
    $today = \Carbon\CarbonImmutable::now('UTC')->startOfDay();
    $earliest = $today->subDays(120)->toDateString();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($earliest);
    // Window: 90 days × 100k cents = 9_000_000 net.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 9_000_000,
            'window_orders' => 90,
            'earning_days' => 80,
            'brand_count' => 3,
            'daily_values_json' => json_encode(array_fill(0, 90, 100000)),
        ],
    ]));
    // Prior window: same rate (momentum = 0).
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'prior_net_cents' => 9_000_000],
    ]));
    // YTD.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'ytd_net_cents' => 12_000_000, 'ytd_orders' => 200],
    ]));
    // Best month.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'best_month' => $today->subMonth()->format('Y-m'),
            'best_month_net_cents' => 3_500_000,
        ],
    ]));

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    $request = \App\Http\Requests\Professional\Analytics\AffiliateProjectionsRequest::create(
        '/api/professional/affiliate/projections', 'GET'
    );
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->show($request);
    $body = json_decode($response->getContent(), true);

    expect($body['data']['status'])->toBe('ok');
    expect($body['data']['window']['days'])->toBe(90);
    expect($body['data']['by_currency'])->toHaveCount(1);

    $usd = $body['data']['by_currency'][0];
    expect($usd['currency_code'])->toBe('USD');
    expect($usd['run_rate']['commission_cents_per_day'])->toBe(100000);
    expect($usd['projections']['annual_commission_cents'])->toBe(36500000); // 100k * 365
    expect($usd['projections']['confidence'])->toBe('high'); // 120d history + zero variance
    expect($usd['momentum']['pct_change_vs_prior_window'])->toBe(0.0);
    expect($usd['ytd']['commission_cents'])->toBe(12000000);
});
```

- [ ] **Step 2: Run test to verify pass**

Run: `./vendor/bin/pest tests/Feature/Analytics/AffiliateProjectionsControllerTest.php --filter="happy" -v`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Analytics/AffiliateProjectionsControllerTest.php
git commit -m "test(analytics): feature test for happy-path projections with 90d history"
```

---

### Task 16: Feature test — authorization denial

**Files:**
- Modify: `tests/Feature/Analytics/AffiliateProjectionsControllerTest.php`

- [ ] **Step 1: Append the test**

```php
it('throws AuthorizationException when policy denies (defense-in-depth check)', function () {
    // Force the policy to deny by passing a Professional whose id differs from the resolved
    // affiliate_professional_id. We achieve this by mocking the policy directly.
    \Illuminate\Support\Facades\Gate::define('viewProjections', fn ($pro, $skeleton) => false);

    $request = \App\Http\Requests\Professional\Analytics\AffiliateProjectionsRequest::create(
        '/api/professional/affiliate/projections', 'GET'
    );
    $request->attributes->set('professional', $this->professional);

    $this->controller->show($request);
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);
```

- [ ] **Step 2: Run test to verify pass**

Run: `./vendor/bin/pest tests/Feature/Analytics/AffiliateProjectionsControllerTest.php --filter="AuthorizationException" -v`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Analytics/AffiliateProjectionsControllerTest.php
git commit -m "test(analytics): feature test for projections policy denial path"
```

---

### Task 17: Feature test — cache invalidation on commerce write

**Files:**
- Modify: `tests/Feature/Analytics/AffiliateProjectionsControllerTest.php`

- [ ] **Step 1: Append the test**

```php
it('invalidates the projections cache when AnalyticsCacheService::invalidateAnalytics is called', function () {
    $proId = (string) $this->professional->id;
    $cacheKey = \App\Services\Cache\CacheKeyGenerator::affiliateProjections($proId);

    Cache::put($cacheKey, ['cached' => true], 600);
    Cache::put($cacheKey . ':stale', ['stale' => true], 6000);

    expect(Cache::has($cacheKey))->toBeTrue();
    expect(Cache::has($cacheKey . ':stale'))->toBeTrue();

    app(\App\Services\Cache\AnalyticsCacheService::class)->invalidateAnalytics($proId);

    expect(Cache::has($cacheKey))->toBeFalse();
    expect(Cache::has($cacheKey . ':stale'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify pass**

Run: `./vendor/bin/pest tests/Feature/Analytics/AffiliateProjectionsControllerTest.php --filter="invalidates" -v`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Analytics/AffiliateProjectionsControllerTest.php
git commit -m "test(analytics): feature test for projections cache invalidation"
```

---

### Task 18: Feature test — explicit `window_days` query param honored

**Files:**
- Modify: `tests/Feature/Analytics/AffiliateProjectionsControllerTest.php`

> Note: as currently written the service ignores `window_days` (always picks adaptively from data history). If we want to honor an explicit `window_days` override from the form request, the service needs a small extension. This task adds that.

- [ ] **Step 1: Modify `AffiliateProjectionsService::build()` to accept an optional override**

Update the signature:

```php
public function build(Professional $professional, ?int $windowDaysOverride = null): array
{
    $tz = $professional->timezone ?: 'UTC';
    $now = CarbonImmutable::now($tz);

    $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);
    $windowDays = $windowDaysOverride !== null
        ? $this->validateOverride($windowDaysOverride, $dataHistoryDays)
        : $this->selectWindowDays($dataHistoryDays);

    // ... rest unchanged
}

/**
 * Validates an explicit window_days override against available history.
 * Returns null (→ insufficient_data) if the affiliate doesn't have enough data
 * for the requested window. Never silently expands or shrinks — the caller
 * asked for N days, they get N days or insufficient_data.
 */
private function validateOverride(int $requested, int $dataHistoryDays): ?int
{
    $tiers = config('partna.commerce_analytics.projections_window_tiers', [90, 60, 30, 14]);
    if (!in_array($requested, $tiers, true)) {
        return null; // form request validation should have caught this; defensive fallback
    }
    return $dataHistoryDays >= $requested ? $requested : null;
}
```

Update the controller to pass the override:

```php
$payload = $this->cacheLock->rememberLocked(
    $cacheKey,
    $ttl,
    fn () => $this->projections->build(
        $professional,
        $request->validated('window_days'),
    ),
);
```

> ⚠️ **Important caching consideration**: when `window_days` is provided, the cache key would still be the same — meaning the first caller's window choice would be served to subsequent callers regardless of their `window_days` param. Two options:
> 1. Bake `window_days` into the cache key (recommended for correctness): `affiliateProjections($proId, $windowDays = null)`.
> 2. Bypass the cache entirely when `window_days` is provided (acceptable since it's an admin/debug path).
>
> Choose option 1. Update `CacheKeyGenerator::affiliateProjections` to accept an optional `?int $windowDays` and append it to the key when set. Don't break the no-arg call.

Update `CacheKeyGenerator::affiliateProjections`:

```php
public static function affiliateProjections(string $professionalId, ?int $windowDays = null): string
{
    $base = "analytics:commerce:affiliate:projections:v1:{$professionalId}";
    return $windowDays === null ? $base : "{$base}:w{$windowDays}";
}
```

Update the controller to pass `$request->validated('window_days')` into the cache key too:

```php
$override = $request->validated('window_days');
$cacheKey = CacheKeyGenerator::affiliateProjections((string) $professional->id, $override);
```

Update `AnalyticsCacheService::invalidateAnalytics` to forget all variants. Pragmatic approach: forget the four allowlist variants explicitly:

```php
foreach ([null, 14, 30, 60, 90] as $w) {
    Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId, $w));
    Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId, $w) . ':stale');
}
```

- [ ] **Step 2: Add the feature test**

```php
it('honors an explicit window_days=30 override even when 90d history is available', function () {
    $today = \Carbon\CarbonImmutable::now('UTC')->startOfDay();
    $earliest = $today->subDays(120)->toDateString();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($earliest);
    $rollupMock->shouldReceive('get')->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 1,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    $request = \App\Http\Requests\Professional\Analytics\AffiliateProjectionsRequest::create(
        '/api/professional/affiliate/projections?window_days=30', 'GET'
    );
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->show($request);
    $body = json_decode($response->getContent(), true);

    expect($body['data']['window']['days'])->toBe(30);
});
```

- [ ] **Step 3: Run all tests**

Run: `./vendor/bin/pest tests/Feature/Analytics/AffiliateProjectionsControllerTest.php tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php tests/Unit/Services/Cache -v`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Analytics/AffiliateProjectionsService.php app/Http/Controllers/Api/Professional/Analytics/AffiliateProjectionsController.php app/Services/Cache/CacheKeyGenerator.php app/Services/Cache/AnalyticsCacheService.php tests/Feature/Analytics/AffiliateProjectionsControllerTest.php
git commit -m "feat(analytics): honor window_days override with per-window cache key"
```

---

### Task 19: Document the endpoint + final verification

**Files:**
- Modify: `docs/api.md`

- [ ] **Step 1: Add the endpoint to `docs/api.md`**

Locate the existing affiliate analytics section. Append:

```markdown
### GET /api/professional/affiliate/projections

Returns straight-line annual + year-end forecasts, run-rate, momentum, YTD, best-month, and engagement metrics for the authenticated affiliate.

**Auth:** Bearer Supabase JWT (professional). Defense-in-depth via `CommissionPolicy::viewProjections`.

**Query params:**
- `window_days` (optional, integer): one of `14`, `30`, `60`, `90`. When omitted, the largest window the affiliate has enough data for is selected automatically.

**Caching:** SWR. TTL 5 min, push-invalidated on every order/edit/cancel/refund webhook.

**Response (status=ok):**
```json
{
  "data": {
    "as_of": "2026-05-07T14:23:11+00:00",
    "data_history_days": 47,
    "status": "ok",
    "window": { "days": 30, "from": "2026-04-07", "to": "2026-05-06" },
    "engagement": { "earning_days_count": 22, "active_brand_count": 2 },
    "by_currency": [
      {
        "currency_code": "USD",
        "run_rate": { "commission_cents_per_day": 4231, "orders_per_day": 1.2 },
        "projections": {
          "annual_commission_cents": 1544315,
          "year_end_commission_cents": 1102000,
          "annual_orders": 438,
          "confidence": "medium"
        },
        "momentum": { "pct_change_vs_prior_window": 0.23, "prior_run_rate_cents_per_day": 3440 },
        "ytd": {
          "commission_cents": 612000,
          "orders_count": 178,
          "best_month": "2026-03",
          "best_month_commission_cents": 184000
        }
      }
    ]
  }
}
```

**Response (status=insufficient_data):** `window` is `null`, `by_currency` is `[]`. Returned when the affiliate has < 14 days of history.
```

- [ ] **Step 2: Run the full suite**

Run: `composer test`
Expected: ALL PASS, no Laravel-migration guard violations.

- [ ] **Step 3: Run Pint to fix style**

Run: `php artisan pint`
Expected: clean output.

- [ ] **Step 4: Final commit**

```bash
git add docs/api.md
git commit -m "docs: document affiliate projections endpoint"
```

---

## Self-review (run before handing off)

**Spec coverage:**
- [x] Tier 1: `projected_annual_commission_cents` → Task 4
- [x] Tier 1: `projected_annual_orders` → Task 4
- [x] Tier 1: `daily_run_rate_cents` → Task 4 (`run_rate.commission_cents_per_day`)
- [x] Tier 1: `projection_window_days` → Task 4 (`window.days`)
- [x] Tier 1: `projection_confidence` → Task 6
- [x] Tier 1: `data_history_days` → Task 3
- [x] Tier 2: `year_to_date_commission_cents` → Task 7
- [x] Tier 2: `projected_year_end_cents` → Task 7
- [x] Tier 2: `momentum_pct` → Task 5
- [x] Tier 2: `best_month_commission_cents` + `best_month` → Task 7
- [x] Tier 2: `earning_days_count` → Task 4 (engagement aggregate)
- [x] Multi-currency support → Tasks 4, 5, 7
- [x] Cache invalidation on writes → Task 13
- [x] Form Request validation → Task 8
- [x] Resource class → Task 9
- [x] Policy gate → Tasks 10, 11
- [x] Adaptive window selection → Task 3
- [x] `insufficient_data` path → Tasks 3, 14
- [x] Explicit `window_days` override → Task 18
- [x] Per-window cache key → Task 18

**Placeholder scan:**
- All `'confidence' => 'low'` placeholders are replaced in Task 6.
- All `'ytd' => null` placeholders are replaced in Task 7.
- All `'momentum' => null` placeholders are replaced in Task 5.
- `year_end_commission_cents => 0` placeholder is replaced in Task 7.
- No "TBD", "TODO", or "implement later" present.

**Type consistency:**
- `BrandAffiliateRollup` is referenced in Tasks 10, 11 with consistent imports.
- `AffiliateProjectionsService::build()` signature evolves additively (`?int $windowDaysOverride = null` added in Task 18, no breaking changes).
- `CacheKeyGenerator::affiliateProjections()` signature evolves additively (Task 18 adds optional `?int $windowDays`).
- All money fields are `int` (cents) end-to-end. `orders_per_day` is `float`. `pct_change_vs_prior_window` is `float|null`.

---

## Operator runbook (post-deploy)

- **TTL tuning**: bump `COMMERCE_PROJECTIONS_TTL_SECONDS` env var if the rollup write rate spikes — invalidation will still keep it fresh, longer TTL just reduces baseline recompute load.
- **Cache version bump**: when changing the response shape, change `v1` to `v2` in `CacheKeyGenerator::affiliateProjections`. Old keys evict naturally; no manual cache flush needed.
- **Confidence tuning**: thresholds are config-driven (`projections_confidence_high.max_cv` etc). Lower if early-pilot affiliates report "high" feels too generous; raise to be stricter.
- **Adding a new tier (e.g., 180 days)**: add it to `projections_window_tiers` config + add it to the `AffiliateProjectionsRequest` `in:...` rule + bump cache key version.
- **Performance regression**: if rollup queries slow down past 90 days × N brands, the bottleneck will be the four `SELECT FROM brand_affiliate_rollup` calls in `build()`. Combining them via UNION ALL or batching with a single CTE is a 1-day refactor; the current shape is intentionally readable over micro-optimal.
