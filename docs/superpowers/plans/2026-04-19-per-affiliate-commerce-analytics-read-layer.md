# Per-Affiliate Commerce Analytics — Read Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two API endpoints that expose the existing commerce analytics aggregate tables to the frontend.

**Architecture:** The write pipeline (Shopify order webhook → `CommissionLedgerEntry` → `CommerceAnalyticsAggregateService` → four `analytics.*` daily tables) is already fully implemented. This plan adds only the read layer: two controllers, two cache key methods, and route registrations. No new DB tables, no new services.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Redis cache, PostgreSQL `analytics` schema.

---

## Files

**Create:**
- `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php`
- `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`
- `tests/Feature/Analytics/AffiliateCommerceAnalyticsControllerTest.php`
- `tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php`

**Modify:**
- `app/Services/Cache/CacheKeyGenerator.php` — add 2 methods
- `routes/api/professional.php` — register 2 routes

---

## Background: Existing Tables

The four aggregate tables (all in the `analytics` PostgreSQL schema) are already populated by the write pipeline:

| Table | Keyed by | Purpose |
|---|---|---|
| `analytics.professional_metrics_daily` | `(affiliate_professional_id, day, currency_code)` | Affiliate's totals across all brands |
| `analytics.brand_metrics_daily` | `(brand_professional_id, day, currency_code)` | Brand's totals across all affiliates |
| `analytics.brand_affiliate_daily` | `(brand_professional_id, affiliate_professional_id, day, currency_code)` | Per-pair breakdown |
| `analytics.brand_commission_daily` | `(brand_professional_id, affiliate_professional_id, payout_status, day, currency_code)` | Commission status breakdown |

---

## Endpoint Contracts

### `GET /api/professional/affiliate/commerce-analytics`

Query params: `from` (Y-m-d), `to` (Y-m-d), `days` (integer 1–365, default 30). `from`/`to` must be provided together.

```json
{
  "data": {
    "range": { "from": "2026-03-20", "to": "2026-04-19" },
    "totals": {
      "orders_count": 42,
      "gross_cents": 180000,
      "refunded_cents": 5000,
      "net_cents": 175000,
      "commission_accrued_cents": 18000,
      "commission_reversed_cents": 500,
      "commission_paid_cents": 15000,
      "currency_code": "AUD"
    },
    "timeseries": [
      { "bucket": "2026-04-18", "orders_count": 3, "gross_cents": 12000, "net_cents": 12000, "commission_accrued_cents": 1200 }
    ]
  }
}
```

### `GET /api/professional/brand/commerce-analytics`

Same query params. Returns brand totals + per-affiliate breakdown + commission status summary. The `commission_summary` combines `brand_commission_daily` — no separate endpoint needed.

```json
{
  "data": {
    "range": { "from": "2026-03-20", "to": "2026-04-19" },
    "totals": {
      "orders_count": 120,
      "gross_cents": 500000,
      "refunded_cents": 20000,
      "net_cents": 480000,
      "currency_code": "AUD"
    },
    "timeseries": [
      { "bucket": "2026-04-18", "orders_count": 10, "gross_cents": 40000, "net_cents": 38000 }
    ],
    "affiliates": [
      {
        "affiliate_professional_id": "uuid",
        "orders_count": 15,
        "gross_cents": 60000,
        "net_cents": 58000,
        "commission_net_cents": 6000,
        "customers_count": 5,
        "currency_code": "AUD"
      }
    ],
    "commission_summary": {
      "pending_cents": 5000,
      "approved_cents": 2000,
      "paid_cents": 10000,
      "reversed_cents": 500,
      "currency_code": "AUD"
    }
  }
}
```

---

## Task 1: Cache Key Methods

**Files:**
- Modify: `app/Services/Cache/CacheKeyGenerator.php`

- [ ] **Step 1: Add two cache key methods**

Open `app/Services/Cache/CacheKeyGenerator.php`. After the `bookingAnalytics` method (around line 116), add:

```php
public static function affiliateCommerceAnalytics(string $professionalId, string $from, string $to): string
{
    return "analytics:commerce:affiliate:{$professionalId}:{$from}:{$to}";
}

public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
{
    return "analytics:commerce:brand:{$professionalId}:{$from}:{$to}";
}
```

- [ ] **Step 2: Verify no syntax errors**

```bash
php artisan about 2>&1 | head -5
```

Expected: Application info displayed, no parse errors.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Cache/CacheKeyGenerator.php
git commit -m "feat(analytics): add commerce analytics cache key methods"
```

---

## Task 2: Affiliate Commerce Analytics (TDD)

**Files:**
- Create: `tests/Feature/Analytics/AffiliateCommerceAnalyticsControllerTest.php`
- Create: `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php`
- Modify: `routes/api/professional.php`

> **Testing note:** The `analytics.*` schema tables don't exist in the SQLite test database. Tests call the controller method directly (bypassing HTTP routing), setting the `professional` request attribute manually. DB calls in happy-path tests are mocked at the facade level with Mockery.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Analytics/AffiliateCommerceAnalyticsControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->controller = new AffiliateCommerceAnalyticsController();
    $this->professional = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'UTC']);
});

it('throws ValidationException when from is provided without to', function () {
    $request = Request::create('/', 'GET', ['from' => '2026-04-01']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('throws ValidationException when to is provided without from', function () {
    $request = Request::create('/', 'GET', ['to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('throws ValidationException when from is after to', function () {
    $request = Request::create('/', 'GET', ['from' => '2026-04-19', 'to' => '2026-04-01']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('throws ValidationException for invalid date format', function () {
    $request = Request::create('/', 'GET', ['from' => '19-04-2026', 'to' => '20-04-2026']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('returns zero totals and empty timeseries when no data exists', function () {
    Cache::fake();

    $queryMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $queryMock->shouldReceive('where')->andReturnSelf();
    $queryMock->shouldReceive('whereBetween')->andReturnSelf();
    $queryMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')
        ->with('analytics.professional_metrics_daily')
        ->once()
        ->andReturn($queryMock);

    $request = Request::create('/', 'GET', ['from' => '2026-04-01', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true)['data'];

    expect($data['range'])->toBe(['from' => '2026-04-01', 'to' => '2026-04-19'])
        ->and($data['totals']['orders_count'])->toBe(0)
        ->and($data['totals']['gross_cents'])->toBe(0)
        ->and($data['totals']['commission_accrued_cents'])->toBe(0)
        ->and($data['timeseries'])->toBe([]);
});

it('defaults to last 30 days when no range params are given', function () {
    Cache::fake();

    $queryMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $queryMock->shouldReceive('where')->andReturnSelf();
    $queryMock->shouldReceive('whereBetween')->andReturnSelf();
    $queryMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')
        ->with('analytics.professional_metrics_daily')
        ->andReturn($queryMock);

    $request = Request::create('/', 'GET');
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true)['data'];

    expect($data['range']['from'])->toBe(now()->subDays(29)->toDateString())
        ->and($data['range']['to'])->toBe(now()->toDateString());
});

it('returns timeseries from daily rows', function () {
    Cache::fake();

    $queryMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $queryMock->shouldReceive('where')->andReturnSelf();
    $queryMock->shouldReceive('whereBetween')->andReturnSelf();
    $queryMock->shouldReceive('get')->andReturn(collect([
        (object) [
            'day' => '2026-04-18',
            'currency_code' => 'AUD',
            'orders_count' => 3,
            'gross_cents' => 12000,
            'refunded_cents' => 0,
            'net_cents' => 12000,
            'commission_accrued_cents' => 1200,
            'commission_reversed_cents' => 0,
            'commission_paid_cents' => 0,
        ],
        (object) [
            'day' => '2026-04-19',
            'currency_code' => 'AUD',
            'orders_count' => 1,
            'gross_cents' => 5000,
            'refunded_cents' => 0,
            'net_cents' => 5000,
            'commission_accrued_cents' => 500,
            'commission_reversed_cents' => 0,
            'commission_paid_cents' => 0,
        ],
    ]));

    DB::shouldReceive('table')
        ->with('analytics.professional_metrics_daily')
        ->andReturn($queryMock);

    $request = Request::create('/', 'GET', ['from' => '2026-04-18', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true)['data'];

    expect($data['totals']['orders_count'])->toBe(4)
        ->and($data['totals']['gross_cents'])->toBe(17000)
        ->and($data['totals']['currency_code'])->toBe('AUD')
        ->and($data['timeseries'])->toHaveCount(2)
        ->and($data['timeseries'][0]['bucket'])->toBe('2026-04-18')
        ->and($data['timeseries'][0]['orders_count'])->toBe(3);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer test -- --filter="AffiliateCommerceAnalytics"
```

Expected: All tests fail with `Class "App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController" not found`.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AffiliateCommerceAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * Affiliate's own commerce performance summary.
     * Reads from analytics.professional_metrics_daily (pre-aggregated per day per currency).
     * When multiple currencies exist, the one with the most orders is used for totals.
     *
     * @return JsonResponse{ data: { range: {from: string, to: string}, totals: array, timeseries: array } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;

        $filters = $this->resolveFilters($request);
        $from = $filters['from'];
        $to = $filters['to'];

        $cacheKey = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, $from, $to);

        return $this->success(Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $from, $to): array {
            $rows = DB::table('analytics.professional_metrics_daily')
                ->where('affiliate_professional_id', $professionalId)
                ->whereBetween('day', [$from, $to])
                ->get();

            // Pick dominant currency (most orders); fall back to AUD if no data.
            $currencyCode = $rows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
            $primary = $rows->filter(fn ($r) => $r->currency_code === $currencyCode);

            $totals = [
                'orders_count' => (int) $primary->sum('orders_count'),
                'gross_cents' => (int) $primary->sum('gross_cents'),
                'refunded_cents' => (int) $primary->sum('refunded_cents'),
                'net_cents' => (int) $primary->sum('net_cents'),
                'commission_accrued_cents' => (int) $primary->sum('commission_accrued_cents'),
                'commission_reversed_cents' => (int) $primary->sum('commission_reversed_cents'),
                'commission_paid_cents' => (int) $primary->sum('commission_paid_cents'),
                'currency_code' => strtoupper($currencyCode),
            ];

            $timeseries = $primary->sortBy('day')->map(fn ($row) => [
                'bucket' => (string) $row->day,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
                'commission_accrued_cents' => (int) $row->commission_accrued_cents,
            ])->values()->all();

            return [
                'range' => ['from' => $from, 'to' => $to],
                'totals' => $totals,
                'timeseries' => $timeseries,
            ];
        }));
    }

    private function resolveFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $hasFrom = isset($validated['from']);
        $hasTo = isset($validated['to']);

        if ($hasFrom xor $hasTo) {
            throw ValidationException::withMessages([
                'from' => ['from and to must be provided together.'],
                'to' => ['from and to must be provided together.'],
            ]);
        }

        if ($hasFrom && $hasTo) {
            $from = Carbon::createFromFormat('Y-m-d', (string) $validated['from']);
            $to = Carbon::createFromFormat('Y-m-d', (string) $validated['to']);

            if ($from->gt($to)) {
                throw ValidationException::withMessages([
                    'from' => ['from must be before to.'],
                ]);
            }

            return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }

        $days = max(1, min(365, (int) ($validated['days'] ?? 30)));

        return [
            'from' => now()->subDays($days - 1)->toDateString(),
            'to' => now()->toDateString(),
        ];
    }
}
```

- [ ] **Step 4: Register the route**

Open `routes/api/professional.php`. Add the import near the top with the other `use` statements:

```php
use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
```

Inside the `Route::middleware(['supabase.jwt', 'current.pro', 'throttle:authenticated'])->group(...)` block, add near the booking analytics route:

```php
Route::get('/affiliate/commerce-analytics', [AffiliateCommerceAnalyticsController::class, 'overview']);
```

- [ ] **Step 5: Run the tests**

```bash
composer test -- --filter="AffiliateCommerceAnalytics"
```

Expected: All 7 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php \
        routes/api/professional.php \
        tests/Feature/Analytics/AffiliateCommerceAnalyticsControllerTest.php
git commit -m "feat(analytics): add affiliate commerce analytics overview endpoint"
```

---

## Task 3: Brand Commerce Analytics (TDD)

**Files:**
- Create: `tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php`
- Create: `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`
- Modify: `routes/api/professional.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php`:

```php
<?php

use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->controller = new BrandCommerceAnalyticsController();
    $this->professional = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'UTC']);
});

// Helper: returns a Mockery query builder that yields an empty collection.
function emptyQueryMock(): \Illuminate\Database\Query\Builder
{
    $mock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $mock->shouldReceive('where')->andReturnSelf();
    $mock->shouldReceive('whereBetween')->andReturnSelf();
    $mock->shouldReceive('get')->andReturn(collect());
    return $mock;
}

it('throws ValidationException when from is provided without to', function () {
    $request = Request::create('/', 'GET', ['from' => '2026-04-01']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('throws ValidationException when from is after to', function () {
    $request = Request::create('/', 'GET', ['from' => '2026-04-19', 'to' => '2026-04-01']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('returns correct empty response shape when no data exists', function () {
    Cache::fake();

    DB::shouldReceive('table')->with('analytics.brand_metrics_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_affiliate_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_commission_daily')->andReturn(emptyQueryMock());

    $request = Request::create('/', 'GET', ['from' => '2026-04-01', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true)['data'];

    expect($data['range'])->toBe(['from' => '2026-04-01', 'to' => '2026-04-19'])
        ->and($data['totals']['orders_count'])->toBe(0)
        ->and($data['affiliates'])->toBe([])
        ->and($data['timeseries'])->toBe([])
        ->and($data['commission_summary']['pending_cents'])->toBe(0)
        ->and($data['commission_summary']['approved_cents'])->toBe(0)
        ->and($data['commission_summary']['paid_cents'])->toBe(0)
        ->and($data['commission_summary']['reversed_cents'])->toBe(0);
});

it('groups affiliate breakdown by affiliate_professional_id', function () {
    Cache::fake();
    $affiliateId = (string) Str::uuid();

    $affiliateMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $affiliateMock->shouldReceive('where')->andReturnSelf();
    $affiliateMock->shouldReceive('whereBetween')->andReturnSelf();
    $affiliateMock->shouldReceive('get')->andReturn(collect([
        (object) [
            'day' => '2026-04-18',
            'affiliate_professional_id' => $affiliateId,
            'currency_code' => 'AUD',
            'orders_count' => 5,
            'gross_cents' => 25000,
            'refunded_cents' => 0,
            'net_cents' => 25000,
            'commission_net_cents' => 2500,
            'customers_count' => 3,
        ],
        (object) [
            'day' => '2026-04-19',
            'affiliate_professional_id' => $affiliateId,
            'currency_code' => 'AUD',
            'orders_count' => 2,
            'gross_cents' => 10000,
            'refunded_cents' => 0,
            'net_cents' => 10000,
            'commission_net_cents' => 1000,
            'customers_count' => 1,
        ],
    ]));

    DB::shouldReceive('table')->with('analytics.brand_metrics_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_affiliate_daily')->andReturn($affiliateMock);
    DB::shouldReceive('table')->with('analytics.brand_commission_daily')->andReturn(emptyQueryMock());

    $request = Request::create('/', 'GET', ['from' => '2026-04-18', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true)['data'];

    expect($data['affiliates'])->toHaveCount(1)
        ->and($data['affiliates'][0]['affiliate_professional_id'])->toBe($affiliateId)
        ->and($data['affiliates'][0]['orders_count'])->toBe(7)
        ->and($data['affiliates'][0]['gross_cents'])->toBe(35000)
        ->and($data['affiliates'][0]['commission_net_cents'])->toBe(3500)
        ->and($data['affiliates'][0]['customers_count'])->toBe(4);
});

it('summarises commission rows by payout_status', function () {
    Cache::fake();

    $commissionMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $commissionMock->shouldReceive('where')->andReturnSelf();
    $commissionMock->shouldReceive('whereBetween')->andReturnSelf();
    $commissionMock->shouldReceive('get')->andReturn(collect([
        (object) ['payout_status' => 'pending',  'currency_code' => 'AUD', 'net_outstanding_cents' => 3000, 'payout_cents' => 0, 'reversal_cents' => 0],
        (object) ['payout_status' => 'pending',  'currency_code' => 'AUD', 'net_outstanding_cents' => 2000, 'payout_cents' => 0, 'reversal_cents' => 0],
        (object) ['payout_status' => 'paid',     'currency_code' => 'AUD', 'net_outstanding_cents' => 0,    'payout_cents' => 8000, 'reversal_cents' => 0],
        (object) ['payout_status' => 'reversed', 'currency_code' => 'AUD', 'net_outstanding_cents' => 0,    'payout_cents' => 0,    'reversal_cents' => 500],
    ]));

    DB::shouldReceive('table')->with('analytics.brand_metrics_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_affiliate_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_commission_daily')->andReturn($commissionMock);

    $request = Request::create('/', 'GET', ['from' => '2026-04-01', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $summary = json_decode($response->getContent(), true)['data']['commission_summary'];

    expect($summary['pending_cents'])->toBe(5000)
        ->and($summary['paid_cents'])->toBe(8000)
        ->and($summary['reversed_cents'])->toBe(500)
        ->and($summary['approved_cents'])->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer test -- --filter="BrandCommerceAnalytics"
```

Expected: All tests fail — `Class "App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController" not found`.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrandCommerceAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * Brand's commerce performance overview.
     * Returns brand-level totals (brand_metrics_daily), per-affiliate breakdown
     * (brand_affiliate_daily), and commission status summary (brand_commission_daily).
     * All three tables are queried and merged into a single cached response.
     *
     * @return JsonResponse{ data: { range, totals, timeseries, affiliates, commission_summary } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;

        $filters = $this->resolveFilters($request);
        $from = $filters['from'];
        $to = $filters['to'];

        $cacheKey = CacheKeyGenerator::brandCommerceAnalytics($professionalId, $from, $to);

        return $this->success(Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $from, $to): array {
            // Brand-level totals and daily timeseries
            $brandRows = DB::table('analytics.brand_metrics_daily')
                ->where('brand_professional_id', $professionalId)
                ->whereBetween('day', [$from, $to])
                ->get();

            $currencyCode = $brandRows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
            $primaryBrand = $brandRows->filter(fn ($r) => $r->currency_code === $currencyCode);

            $totals = [
                'orders_count' => (int) $primaryBrand->sum('orders_count'),
                'gross_cents' => (int) $primaryBrand->sum('gross_cents'),
                'refunded_cents' => (int) $primaryBrand->sum('refunded_cents'),
                'net_cents' => (int) $primaryBrand->sum('net_cents'),
                'currency_code' => strtoupper($currencyCode),
            ];

            $timeseries = $primaryBrand->sortBy('day')->map(fn ($row) => [
                'bucket' => (string) $row->day,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
            ])->values()->all();

            // Per-affiliate breakdown — sum each affiliate's rows across the date range
            $affiliateRows = DB::table('analytics.brand_affiliate_daily')
                ->where('brand_professional_id', $professionalId)
                ->whereBetween('day', [$from, $to])
                ->get()
                ->groupBy('affiliate_professional_id');

            $affiliates = $affiliateRows->map(function ($rows, $affiliateId) {
                $affiliateCurrency = $rows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
                $primary = $rows->filter(fn ($r) => $r->currency_code === $affiliateCurrency);

                return [
                    'affiliate_professional_id' => $affiliateId,
                    'orders_count' => (int) $primary->sum('orders_count'),
                    'gross_cents' => (int) $primary->sum('gross_cents'),
                    'net_cents' => (int) $primary->sum('net_cents'),
                    'commission_net_cents' => (int) $primary->sum('commission_net_cents'),
                    'customers_count' => (int) $primary->sum('customers_count'),
                    'currency_code' => strtoupper($affiliateCurrency),
                ];
            })->values()->all();

            // Commission status totals across all affiliates for the date range
            $commissionRows = DB::table('analytics.brand_commission_daily')
                ->where('brand_professional_id', $professionalId)
                ->whereBetween('day', [$from, $to])
                ->get()
                ->groupBy('payout_status');

            $commissionSummary = [
                'pending_cents' => (int) ($commissionRows->get('pending')?->sum('net_outstanding_cents') ?? 0),
                'approved_cents' => (int) ($commissionRows->get('approved')?->sum('net_outstanding_cents') ?? 0),
                'paid_cents' => (int) ($commissionRows->get('paid')?->sum('payout_cents') ?? 0),
                'reversed_cents' => (int) ($commissionRows->get('reversed')?->sum('reversal_cents') ?? 0),
                'currency_code' => strtoupper($currencyCode),
            ];

            return [
                'range' => ['from' => $from, 'to' => $to],
                'totals' => $totals,
                'timeseries' => $timeseries,
                'affiliates' => $affiliates,
                'commission_summary' => $commissionSummary,
            ];
        }));
    }

    private function resolveFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $hasFrom = isset($validated['from']);
        $hasTo = isset($validated['to']);

        if ($hasFrom xor $hasTo) {
            throw ValidationException::withMessages([
                'from' => ['from and to must be provided together.'],
                'to' => ['from and to must be provided together.'],
            ]);
        }

        if ($hasFrom && $hasTo) {
            $from = Carbon::createFromFormat('Y-m-d', (string) $validated['from']);
            $to = Carbon::createFromFormat('Y-m-d', (string) $validated['to']);

            if ($from->gt($to)) {
                throw ValidationException::withMessages([
                    'from' => ['from must be before to.'],
                ]);
            }

            return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }

        $days = max(1, min(365, (int) ($validated['days'] ?? 30)));

        return [
            'from' => now()->subDays($days - 1)->toDateString(),
            'to' => now()->toDateString(),
        ];
    }
}
```

- [ ] **Step 4: Register the route**

Open `routes/api/professional.php`. Add the import:

```php
use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
```

Inside the `Route::middleware(['supabase.jwt', 'current.pro', 'throttle:authenticated'])->group(...)` block, next to the affiliate route added in Task 2:

```php
Route::get('/brand/commerce-analytics', [BrandCommerceAnalyticsController::class, 'overview']);
```

- [ ] **Step 5: Run commerce analytics tests**

```bash
composer test -- --filter="CommerceAnalytics"
```

Expected: All tests across both test files pass.

- [ ] **Step 6: Run full test suite**

```bash
composer test
```

Expected: All existing tests still pass — no regressions.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php \
        routes/api/professional.php \
        tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php
git commit -m "feat(analytics): add brand commerce analytics overview endpoint"
```
