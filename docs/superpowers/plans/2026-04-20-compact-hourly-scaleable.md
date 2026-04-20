# CompactHourlyAnalytics — Scaleable Streaming Dispatch

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix `CompactHourlyAnalytics` to stream rows instead of loading all into memory, eliminate the N+1 ledger query in `compactCommerce` via a JOIN, and dispatch jobs in chunked batches — safe at 100k professionals.

**Architecture:** Three changes applied together: (1) `->get()` replaced with `DB::scalar()` for counts + `->lazy()` for the dispatch stream so PHP memory stays O(chunk_size) regardless of row count; (2) the `compactCommerce` N+1 (one ledger query per brand-day pair) collapsed into a single cross-schema JOIN that the DB evaluates once; (3) individual `dispatch()` calls replaced with chunked `Bus::batch()` (default 500 jobs/batch) so Horizon queue pressure is bounded. All three daily aggregate jobs also receive the `Batchable` trait so batch completion is properly tracked.

**Tech Stack:** Laravel 12, Pest 4, `Bus::fake()`, `LazyCollection`, `DB::scalar()`, Mockery per-table mocks.

---

## File Map

| File | Action |
|------|--------|
| `app/Jobs/Analytics/RebuildSiteDailyAggregatesJob.php` | Modify — add `Batchable` trait |
| `app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php` | Modify — add `Batchable` trait |
| `app/Jobs/Analytics/RebuildCommerceDailyAggregatesJob.php` | Modify — add `Batchable` trait |
| `app/Console/Commands/CompactHourlyAnalytics.php` | Modify — add `--chunk-size`, fix all three compact methods |
| `tests/Feature/Analytics/CompactHourlyAnalyticsCommandTest.php` | Create — full command test suite |

---

### Task 1: Add `Batchable` to all three daily aggregate jobs

No tests needed — same pattern established for the hourly jobs. Without `Batchable`, `Bus::batch()` dispatches the jobs but Horizon never marks batches complete.

**Files:**
- Modify: `app/Jobs/Analytics/RebuildSiteDailyAggregatesJob.php`
- Modify: `app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php`
- Modify: `app/Jobs/Analytics/RebuildCommerceDailyAggregatesJob.php`

- [ ] **Step 1: Add `Batchable` to `RebuildSiteDailyAggregatesJob`**

In `app/Jobs/Analytics/RebuildSiteDailyAggregatesJob.php`, add the import after `use Illuminate\Bus\Queueable;`:

```php
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
```

And update the trait line:

```php
use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
```

- [ ] **Step 2: Add `Batchable` to `RebuildBookingDailyAggregatesJob`**

Same edit in `app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php`:

```php
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
```

```php
use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
```

- [ ] **Step 3: Add `Batchable` to `RebuildCommerceDailyAggregatesJob`**

Same edit in `app/Jobs/Analytics/RebuildCommerceDailyAggregatesJob.php`:

```php
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
```

```php
use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
```

- [ ] **Step 4: Run full suite to confirm no regressions**

```bash
composer test
```

Expected: same 4 pre-existing failures, all others pass.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Analytics/RebuildSiteDailyAggregatesJob.php \
        app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php \
        app/Jobs/Analytics/RebuildCommerceDailyAggregatesJob.php
git commit -m "feat(analytics): add Batchable trait to daily aggregate jobs"
```

---

### Task 2: Write failing tests for the compact command

**Files:**
- Create: `tests/Feature/Analytics/CompactHourlyAnalyticsCommandTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

use App\Jobs\Analytics\RebuildBookingDailyAggregatesJob;
use App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteDailyAggregatesJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

beforeEach(function () {
    Bus::fake();
});

// ─── compactSite ──────────────────────────────────────────────────────────────

it('dispatches one site batch when professional-day pairs fit in a single chunk', function () {
    $siteQuery = Mockery::mock();
    $siteQuery->shouldReceive('where')->andReturnSelf();
    $siteQuery->shouldReceive('selectRaw')->andReturnSelf();
    $siteQuery->shouldReceive('groupBy')->andReturnSelf();
    $siteQuery->shouldReceive('lazy')->andReturn(LazyCollection::make([
        (object) ['professional_id' => 'uuid-1', 'day' => '2026-04-18'],
        (object) ['professional_id' => 'uuid-2', 'day' => '2026-04-18'],
    ]));
    $siteQuery->shouldReceive('count')->andReturn(10);
    $siteQuery->shouldReceive('delete')->andReturn(10);

    DB::shouldReceive('table')->with('analytics.site_metrics_hourly')->andReturn($siteQuery);
    DB::shouldReceive('scalar')->andReturn(2);

    $this->artisan('sidest:analytics:compact-hourly', ['--chunk-size' => 500])
        ->assertSuccessful();

    // 2 pairs, 1 chunk → 1 batch
    Bus::assertBatchCount(1);
    Bus::assertBatched(fn ($b) => collect($b->jobs)->contains(fn ($j) => $j instanceof RebuildSiteDailyAggregatesJob));
});

it('splits site dispatch into multiple batches when pairs exceed chunk size', function () {
    $rows = collect(range(1, 1100))->map(fn ($i) => (object) ['professional_id' => "uuid-{$i}", 'day' => '2026-04-18']);

    $siteQuery = Mockery::mock();
    $siteQuery->shouldReceive('where')->andReturnSelf();
    $siteQuery->shouldReceive('selectRaw')->andReturnSelf();
    $siteQuery->shouldReceive('groupBy')->andReturnSelf();
    $siteQuery->shouldReceive('lazy')->andReturn(LazyCollection::make($rows));
    $siteQuery->shouldReceive('count')->andReturn(5000);
    $siteQuery->shouldReceive('delete')->andReturn(5000);

    DB::shouldReceive('table')->with('analytics.site_metrics_hourly')->andReturn($siteQuery);
    DB::shouldReceive('scalar')->andReturn(1100);

    $this->artisan('sidest:analytics:compact-hourly', ['--chunk-size' => 500])
        ->assertSuccessful();

    // ceil(1100 / 500) = 3 batches
    Bus::assertBatchCount(3);
});

it('dispatches nothing in site dry-run mode', function () {
    $siteQuery = Mockery::mock();
    $siteQuery->shouldReceive('where')->andReturnSelf();
    $siteQuery->shouldReceive('selectRaw')->andReturnSelf();
    $siteQuery->shouldReceive('groupBy')->andReturnSelf();
    $siteQuery->shouldReceive('count')->andReturn(10);
    $siteQuery->shouldReceive('lazy')->never();
    $siteQuery->shouldReceive('delete')->never();

    DB::shouldReceive('table')->with('analytics.site_metrics_hourly')->andReturn($siteQuery);
    DB::shouldReceive('scalar')->andReturn(5);

    $this->artisan('sidest:analytics:compact-hourly', ['--dry-run' => true])
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});

// ─── compactBooking ───────────────────────────────────────────────────────────

it('dispatches one booking batch when professional-day pairs fit in a single chunk', function () {
    $bookingQuery = Mockery::mock();
    $bookingQuery->shouldReceive('where')->andReturnSelf();
    $bookingQuery->shouldReceive('selectRaw')->andReturnSelf();
    $bookingQuery->shouldReceive('groupBy')->andReturnSelf();
    $bookingQuery->shouldReceive('lazy')->andReturn(LazyCollection::make([
        (object) ['professional_id' => 'uuid-1', 'day' => '2026-04-18'],
    ]));
    $bookingQuery->shouldReceive('count')->andReturn(5);
    $bookingQuery->shouldReceive('delete')->andReturn(5);

    DB::shouldReceive('table')->with('analytics.booking_metrics_hourly')->andReturn($bookingQuery);
    DB::shouldReceive('scalar')->andReturn(1);

    $this->artisan('sidest:analytics:compact-hourly', ['--chunk-size' => 500])
        ->assertSuccessful();

    Bus::assertBatchCount(1);
    Bus::assertBatched(fn ($b) => collect($b->jobs)->contains(fn ($j) => $j instanceof RebuildBookingDailyAggregatesJob));
});

it('dispatches nothing in booking dry-run mode', function () {
    $bookingQuery = Mockery::mock();
    $bookingQuery->shouldReceive('where')->andReturnSelf();
    $bookingQuery->shouldReceive('selectRaw')->andReturnSelf();
    $bookingQuery->shouldReceive('groupBy')->andReturnSelf();
    $bookingQuery->shouldReceive('count')->andReturn(5);
    $bookingQuery->shouldReceive('lazy')->never();
    $bookingQuery->shouldReceive('delete')->never();

    DB::shouldReceive('table')->with('analytics.booking_metrics_hourly')->andReturn($bookingQuery);
    DB::shouldReceive('scalar')->andReturn(3);

    $this->artisan('sidest:analytics:compact-hourly', ['--dry-run' => true])
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});

// ─── compactCommerce ──────────────────────────────────────────────────────────

it('dispatches commerce batches using brand-affiliate triples from a single JOIN query', function () {
    $joinQuery = Mockery::mock();
    $joinQuery->shouldReceive('join')->andReturnSelf();
    $joinQuery->shouldReceive('where')->andReturnSelf();
    $joinQuery->shouldReceive('selectRaw')->andReturnSelf();
    $joinQuery->shouldReceive('distinct')->andReturnSelf();
    $joinQuery->shouldReceive('lazy')->andReturn(LazyCollection::make([
        (object) ['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-1', 'day' => '2026-04-18'],
        (object) ['brand_professional_id' => 'brand-1', 'affiliate_professional_id' => 'aff-2', 'day' => '2026-04-18'],
    ]));

    $brandDeleteQuery = Mockery::mock();
    $brandDeleteQuery->shouldReceive('where')->andReturnSelf();
    $brandDeleteQuery->shouldReceive('count')->andReturn(5);
    $brandDeleteQuery->shouldReceive('delete')->andReturn(5);

    $affiliateDeleteQuery = Mockery::mock();
    $affiliateDeleteQuery->shouldReceive('where')->andReturnSelf();
    $affiliateDeleteQuery->shouldReceive('count')->andReturn(8);
    $affiliateDeleteQuery->shouldReceive('delete')->andReturn(8);

    DB::shouldReceive('table')->with('analytics.brand_metrics_hourly as bmh')->andReturn($joinQuery);
    DB::shouldReceive('table')->with('analytics.brand_metrics_hourly')->andReturn($brandDeleteQuery);
    DB::shouldReceive('table')->with('analytics.professional_metrics_hourly')->andReturn($affiliateDeleteQuery);
    DB::shouldReceive('scalar')->andReturn(2);

    $this->artisan('sidest:analytics:compact-hourly', ['--chunk-size' => 500])
        ->assertSuccessful();

    // 2 triples, 1 chunk → 1 batch
    Bus::assertBatchCount(1);
    Bus::assertBatched(fn ($b) => collect($b->jobs)->contains(fn ($j) => $j instanceof RebuildCommerceDailyAggregatesJob));
});

it('splits commerce dispatch into multiple batches when triples exceed chunk size', function () {
    $triples = collect(range(1, 1100))->map(fn ($i) => (object) [
        'brand_professional_id'     => 'brand-1',
        'affiliate_professional_id' => "aff-{$i}",
        'day'                       => '2026-04-18',
    ]);

    $joinQuery = Mockery::mock();
    $joinQuery->shouldReceive('join')->andReturnSelf();
    $joinQuery->shouldReceive('where')->andReturnSelf();
    $joinQuery->shouldReceive('selectRaw')->andReturnSelf();
    $joinQuery->shouldReceive('distinct')->andReturnSelf();
    $joinQuery->shouldReceive('lazy')->andReturn(LazyCollection::make($triples));

    $brandDeleteQuery = Mockery::mock();
    $brandDeleteQuery->shouldReceive('where')->andReturnSelf();
    $brandDeleteQuery->shouldReceive('count')->andReturn(100);
    $brandDeleteQuery->shouldReceive('delete')->andReturn(100);

    $affiliateDeleteQuery = Mockery::mock();
    $affiliateDeleteQuery->shouldReceive('where')->andReturnSelf();
    $affiliateDeleteQuery->shouldReceive('count')->andReturn(200);
    $affiliateDeleteQuery->shouldReceive('delete')->andReturn(200);

    DB::shouldReceive('table')->with('analytics.brand_metrics_hourly as bmh')->andReturn($joinQuery);
    DB::shouldReceive('table')->with('analytics.brand_metrics_hourly')->andReturn($brandDeleteQuery);
    DB::shouldReceive('table')->with('analytics.professional_metrics_hourly')->andReturn($affiliateDeleteQuery);
    DB::shouldReceive('scalar')->andReturn(1100);

    $this->artisan('sidest:analytics:compact-hourly', ['--chunk-size' => 500])
        ->assertSuccessful();

    // ceil(1100 / 500) = 3 batches
    Bus::assertBatchCount(3);
});

it('dispatches nothing and does not delete in commerce dry-run mode', function () {
    $joinQuery = Mockery::mock();
    $joinQuery->shouldReceive('join')->never();
    $joinQuery->shouldReceive('lazy')->never();

    $brandDeleteQuery = Mockery::mock();
    $brandDeleteQuery->shouldReceive('where')->andReturnSelf();
    $brandDeleteQuery->shouldReceive('count')->andReturn(5);
    $brandDeleteQuery->shouldReceive('delete')->never();

    $affiliateDeleteQuery = Mockery::mock();
    $affiliateDeleteQuery->shouldReceive('where')->andReturnSelf();
    $affiliateDeleteQuery->shouldReceive('count')->andReturn(8);
    $affiliateDeleteQuery->shouldReceive('delete')->never();

    DB::shouldReceive('table')->with('analytics.brand_metrics_hourly as bmh')->andReturn($joinQuery)->never();
    DB::shouldReceive('table')->with('analytics.brand_metrics_hourly')->andReturn($brandDeleteQuery);
    DB::shouldReceive('table')->with('analytics.professional_metrics_hourly')->andReturn($affiliateDeleteQuery);
    DB::shouldReceive('scalar')->andReturn(2);

    $this->artisan('sidest:analytics:compact-hourly', ['--dry-run' => true])
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --filter CompactHourlyAnalyticsCommandTest
```

Expected: All tests fail — `--chunk-size` option does not exist yet.

---

### Task 3: Implement site and booking fixes

**Files:**
- Modify: `app/Console/Commands/CompactHourlyAnalytics.php`

- [ ] **Step 1: Add imports and `--chunk-size` to the command**

Add to the `use` block at the top:

```php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\LazyCollection;
```

Update `$signature`:

```php
protected $signature = 'sidest:analytics:compact-hourly
    {--dry-run : Show work without mutating data}
    {--chunk-size=500 : Max jobs per batch (default 500)}';
```

Update `handle()` to read and pass `$chunkSize`:

```php
public function handle(): int
{
    $dryRun    = (bool) $this->option('dry-run');
    $chunkSize = max(1, (int) $this->option('chunk-size'));
    $cutoff    = Carbon::now()->utc()->subHours(24)->startOfHour();

    $this->info('Cutoff hour: '.$cutoff->toIso8601String());
    if ($dryRun) {
        $this->warn('Dry run mode is enabled. No rows will be deleted or rebuilt.');
    }

    $this->compactCommerce($cutoff, $dryRun, $chunkSize);
    $this->compactSite($cutoff, $dryRun, $chunkSize);
    $this->compactBooking($cutoff, $dryRun, $chunkSize);

    $this->info('Hourly analytics compaction complete.');

    return self::SUCCESS;
}
```

- [ ] **Step 2: Replace `compactSite` with streaming + chunked batch dispatch**

Replace the entire `compactSite` method:

```php
private function compactSite(Carbon $cutoff, bool $dryRun, int $chunkSize): void
{
    // COUNT at DB level — no rows loaded into PHP
    $staleDayCount = DB::scalar(
        "SELECT COUNT(*) FROM (
            SELECT 1 FROM analytics.site_metrics_hourly
            WHERE hour_start < ?
            GROUP BY professional_id, (hour_start AT TIME ZONE timezone)::date
        ) sub",
        [$cutoff]
    );

    $this->line("Site day rebuild keys: {$staleDayCount}");

    if (! $dryRun) {
        $batchCount = 0;
        DB::table('analytics.site_metrics_hourly')
            ->where('hour_start', '<', $cutoff)
            ->selectRaw('professional_id')
            ->selectRaw('(hour_start AT TIME ZONE timezone)::date as day')
            ->groupBy('professional_id', 'day')
            ->lazy()
            ->chunk($chunkSize)
            ->each(function (LazyCollection $chunk, int $chunkIndex) use (&$batchCount): void {
                $jobs = $chunk->map(
                    static fn ($row) => new RebuildSiteDailyAggregatesJob(
                        (string) $row->professional_id,
                        (string) $row->day
                    )
                )->values()->all();

                Bus::batch($jobs)
                    ->name("site-daily-compact:chunk-{$chunkIndex}")
                    ->allowFailures()
                    ->dispatch();

                $batchCount++;
            });

        $this->line("Site daily batches dispatched: {$batchCount}");
    }

    $staleRows = DB::table('analytics.site_metrics_hourly')
        ->where('hour_start', '<', $cutoff);

    $count = (clone $staleRows)->count();
    if (! $dryRun) {
        $staleRows->delete();
    }

    $this->line("Site hourly rows aged out: {$count}");
}
```

- [ ] **Step 3: Replace `compactBooking` with streaming + chunked batch dispatch**

Replace the entire `compactBooking` method:

```php
private function compactBooking(Carbon $cutoff, bool $dryRun, int $chunkSize): void
{
    $staleDayCount = DB::scalar(
        "SELECT COUNT(*) FROM (
            SELECT 1 FROM analytics.booking_metrics_hourly
            WHERE hour_start < ?
            GROUP BY professional_id, (hour_start AT TIME ZONE timezone)::date
        ) sub",
        [$cutoff]
    );

    $this->line("Booking day rebuild keys: {$staleDayCount}");

    if (! $dryRun) {
        $batchCount = 0;
        DB::table('analytics.booking_metrics_hourly')
            ->where('hour_start', '<', $cutoff)
            ->selectRaw('professional_id')
            ->selectRaw('(hour_start AT TIME ZONE timezone)::date as day')
            ->groupBy('professional_id', 'day')
            ->lazy()
            ->chunk($chunkSize)
            ->each(function (LazyCollection $chunk, int $chunkIndex) use (&$batchCount): void {
                $jobs = $chunk->map(
                    static fn ($row) => new RebuildBookingDailyAggregatesJob(
                        (string) $row->professional_id,
                        (string) $row->day
                    )
                )->values()->all();

                Bus::batch($jobs)
                    ->name("booking-daily-compact:chunk-{$chunkIndex}")
                    ->allowFailures()
                    ->dispatch();

                $batchCount++;
            });

        $this->line("Booking daily batches dispatched: {$batchCount}");
    }

    $staleRows = DB::table('analytics.booking_metrics_hourly')
        ->where('hour_start', '<', $cutoff);

    $count = (clone $staleRows)->count();
    if (! $dryRun) {
        $staleRows->delete();
    }

    $this->line("Booking hourly rows aged out: {$count}");
}
```

- [ ] **Step 4: Run site and booking tests**

```bash
php artisan test --filter CompactHourlyAnalyticsCommandTest
```

Expected: site and booking tests pass; commerce tests still fail (not implemented yet).

---

### Task 4: Implement the commerce fix

**Files:**
- Modify: `app/Console/Commands/CompactHourlyAnalytics.php`

- [ ] **Step 1: Replace `compactCommerce` with JOIN + streaming + chunked batch dispatch**

Replace the entire `compactCommerce` method:

```php
private function compactCommerce(Carbon $cutoff, bool $dryRun, int $chunkSize): void
{
    // COUNT at DB level — no rows loaded into PHP
    $staleBrandDayCount = DB::scalar(
        "SELECT COUNT(*) FROM (
            SELECT 1 FROM analytics.brand_metrics_hourly
            WHERE hour_start < ?
            GROUP BY brand_professional_id, (hour_start AT TIME ZONE timezone)::date
        ) sub",
        [$cutoff]
    );

    $staleAffiliateDayCount = DB::scalar(
        "SELECT COUNT(*) FROM (
            SELECT 1 FROM analytics.professional_metrics_hourly
            WHERE hour_start < ?
            GROUP BY affiliate_professional_id, (hour_start AT TIME ZONE timezone)::date
        ) sub",
        [$cutoff]
    );

    $this->line("Commerce brand-day rebuild keys: {$staleBrandDayCount}");
    $this->line("Commerce affiliate-day rebuild keys: {$staleAffiliateDayCount}");

    // Single JOIN replaces N+1: fetches all (brand, affiliate, day) triples in one query.
    // Streams with lazy() so memory stays O(chunk_size) regardless of row count.
    if (! $dryRun) {
        $batchCount = 0;
        DB::table('analytics.brand_metrics_hourly as bmh')
            ->join(
                'commerce.commission_ledger_entries as cle',
                fn ($join) => $join
                    ->on('cle.brand_professional_id', '=', 'bmh.brand_professional_id')
                    ->whereRaw('cle.occurred_at::date = (bmh.hour_start AT TIME ZONE bmh.timezone)::date')
            )
            ->where('bmh.hour_start', '<', $cutoff)
            ->selectRaw('bmh.brand_professional_id')
            ->selectRaw('cle.affiliate_professional_id')
            ->selectRaw('(bmh.hour_start AT TIME ZONE bmh.timezone)::date as day')
            ->distinct()
            ->lazy()
            ->chunk($chunkSize)
            ->each(function (LazyCollection $chunk, int $chunkIndex) use (&$batchCount): void {
                $jobs = $chunk->map(
                    static fn ($row) => new RebuildCommerceDailyAggregatesJob(
                        (string) $row->brand_professional_id,
                        (string) $row->affiliate_professional_id,
                        (string) $row->day
                    )
                )->values()->all();

                Bus::batch($jobs)
                    ->name("commerce-daily-compact:chunk-{$chunkIndex}")
                    ->allowFailures()
                    ->dispatch();

                $batchCount++;
            });

        $this->line("Commerce daily batches dispatched: {$batchCount}");
    }

    $brandRows     = DB::table('analytics.brand_metrics_hourly')->where('hour_start', '<', $cutoff);
    $affiliateRows = DB::table('analytics.professional_metrics_hourly')->where('hour_start', '<', $cutoff);

    $brandCount     = (clone $brandRows)->count();
    $affiliateCount = (clone $affiliateRows)->count();

    if (! $dryRun) {
        $brandRows->delete();
        $affiliateRows->delete();
    }

    $this->line("Commerce hourly rows aged out: brand={$brandCount}, affiliate={$affiliateCount}");
}
```

- [ ] **Step 2: Run all compact tests**

```bash
php artisan test --filter CompactHourlyAnalyticsCommandTest
```

Expected: All tests pass.

- [ ] **Step 3: Run full test suite**

```bash
composer test
```

Expected: same 4 pre-existing failures, all others pass.

- [ ] **Step 4: Commit all command changes**

```bash
git add app/Console/Commands/CompactHourlyAnalytics.php \
        tests/Feature/Analytics/CompactHourlyAnalyticsCommandTest.php
git commit -m "feat(analytics): stream compact dispatch via lazy cursor, eliminate N+1 with JOIN, chunk into batches"
```

---

## Self-Review

**Spec coverage:**
- ✅ `->get()` removed in all three domains — replaced with `DB::scalar()` for count + `->lazy()` for stream
- ✅ N+1 in `compactCommerce` eliminated — single JOIN query produces all triples
- ✅ `Bus::batch()` + chunking in all three domains
- ✅ `Batchable` added to all three daily aggregate jobs
- ✅ `--chunk-size` option with default 500
- ✅ `dry-run` mode preserved — no dispatch, no delete, counts still shown
- ✅ Tests cover: single chunk, multi-chunk, dry-run, job type verification

**Notes:**
- `DB::scalar()` (Laravel 9.6+) is used for group counts — produces a single aggregate without loading rows into PHP. Unambiguous behavior with `GROUP BY` across all DB engines.
- The JOIN uses the aliased table name `analytics.brand_metrics_hourly as bmh` — this means the DB mock must use exactly that string as the `with()` matcher, which the tests do.
- Pre-existing 4 test failures (`RequestValidationTest`, Shopify design token test) are unrelated and should not change.
- At 100k professionals: `compactSite`/`compactBooking` stream ≤500 rows at a time; `compactCommerce` streams (brand×affiliates) triples ≤500 at a time. Peak PHP memory is bounded at O(chunkSize × job object size) regardless of total row count.
