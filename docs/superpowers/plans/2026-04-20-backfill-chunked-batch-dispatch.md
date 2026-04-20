# Backfill Hourly Analytics — Chunked Batch Dispatch

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `--chunk-size` option to `BackfillHourlyAnalytics` so that instead of one batch per hour containing all professionals, it dispatches multiple smaller batches per hour, capping peak memory usage regardless of professional count.

**Architecture:** The command currently loads all professional IDs into a Collection and dispatches one `Bus::batch()` per hour. This plan wraps that dispatch in a `->chunk(N)->each()` loop, producing `ceil(professionals / chunk_size)` batches per hour instead of one. No job classes change — only the command and its tests.

**Tech Stack:** Laravel 12, Pest 4, `Bus::fake()` for test assertions.

---

## File Map

| File | Action |
|------|--------|
| `app/Console/Commands/BackfillHourlyAnalytics.php` | Modify — add `--chunk-size` option, extract `dispatchSiteBatches` + `dispatchBookingBatches` helpers |
| `tests/Feature/Analytics/BackfillHourlyAnalyticsCommandTest.php` | Create — command tests using `Bus::fake()` + DB mocking |

---

### Task 1: Write failing tests

**Files:**
- Create: `tests/Feature/Analytics/BackfillHourlyAnalyticsCommandTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

use App\Jobs\Analytics\RebuildBookingHourlyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteHourlyAggregatesJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Bus::fake();
});

it('dispatches one batch per hour when professionals fit in a single chunk', function () {
    // 3 professionals, chunk-size 500 → 1 batch per hour
    $professionalIds = ['uuid-1', 'uuid-2', 'uuid-3'];

    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('select')->andReturnSelf();
    DB::shouldReceive('whereBetween')->andReturnSelf();
    DB::shouldReceive('union')->andReturnSelf();
    DB::shouldReceive('distinct')->andReturnSelf();
    DB::shouldReceive('pluck')->andReturn(collect($professionalIds));

    $this->artisan('sidest:analytics:backfill-hourly', [
        '--hours'      => 2,
        '--domains'    => 'site',
        '--chunk-size' => 500,
    ])->assertSuccessful();

    // 2 hours × 1 chunk = 2 batches
    Bus::assertBatchCount(2);
});

it('splits into multiple batches per hour when professionals exceed chunk size', function () {
    // 1100 professionals, chunk-size 500 → ceil(1100/500) = 3 batches per hour
    $professionalIds = collect(range(1, 1100))->map(fn ($i) => "uuid-{$i}");

    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('select')->andReturnSelf();
    DB::shouldReceive('whereBetween')->andReturnSelf();
    DB::shouldReceive('union')->andReturnSelf();
    DB::shouldReceive('distinct')->andReturnSelf();
    DB::shouldReceive('pluck')->andReturn($professionalIds);

    $this->artisan('sidest:analytics:backfill-hourly', [
        '--hours'      => 1,
        '--domains'    => 'site',
        '--chunk-size' => 500,
    ])->assertSuccessful();

    // 1 hour × 3 chunks = 3 batches
    Bus::assertBatchCount(3);
});

it('uses default chunk size of 500 when option is not supplied', function () {
    $professionalIds = collect(range(1, 600))->map(fn ($i) => "uuid-{$i}");

    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('select')->andReturnSelf();
    DB::shouldReceive('whereBetween')->andReturnSelf();
    DB::shouldReceive('union')->andReturnSelf();
    DB::shouldReceive('distinct')->andReturnSelf();
    DB::shouldReceive('pluck')->andReturn($professionalIds);

    $this->artisan('sidest:analytics:backfill-hourly', [
        '--hours'   => 1,
        '--domains' => 'site',
    ])->assertSuccessful();

    // 1 hour × ceil(600/500) = 2 batches
    Bus::assertBatchCount(2);
});

it('dispatches site and booking batches independently with correct job types', function () {
    $ids = ['uuid-a', 'uuid-b'];

    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('select')->andReturnSelf();
    DB::shouldReceive('whereBetween')->andReturnSelf();
    DB::shouldReceive('union')->andReturnSelf();
    DB::shouldReceive('distinct')->andReturnSelf();
    DB::shouldReceive('pluck')->andReturn(collect($ids));

    $this->artisan('sidest:analytics:backfill-hourly', [
        '--hours'      => 1,
        '--domains'    => 'all',
        '--chunk-size' => 500,
    ])->assertSuccessful();

    Bus::assertDispatched(RebuildSiteHourlyAggregatesJob::class);
    Bus::assertDispatched(RebuildBookingHourlyAggregatesJob::class);
});

it('dispatches no batches when no professionals exist in range', function () {
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('select')->andReturnSelf();
    DB::shouldReceive('whereBetween')->andReturnSelf();
    DB::shouldReceive('union')->andReturnSelf();
    DB::shouldReceive('distinct')->andReturnSelf();
    DB::shouldReceive('pluck')->andReturn(collect([]));

    $this->artisan('sidest:analytics:backfill-hourly', [
        '--hours'   => 24,
        '--domains' => 'site',
    ])->assertSuccessful();

    Bus::assertNothingDispatched();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
composer test -- --filter BackfillHourlyAnalyticsCommandTest
```

Expected: All tests fail — `--chunk-size` option does not exist yet, `assertBatchCount` finds 0 batches.

---

### Task 2: Implement chunked dispatch

**Files:**
- Modify: `app/Console/Commands/BackfillHourlyAnalytics.php`

- [ ] **Step 1: Add `--chunk-size` to the signature**

In `BackfillHourlyAnalytics.php`, update the `$signature` property:

```php
protected $signature = 'sidest:analytics:backfill-hourly
    {--hours=24 : Number of trailing hours to backfill (1-168)}
    {--domains=all : all,commerce,site,booking (comma-separated)}
    {--chunk-size=500 : Max professionals per batch (default 500)}';
```

- [ ] **Step 2: Read chunk size in `handle()` and thread it through**

In `handle()`, resolve the chunk size and pass it to both domain methods:

```php
public function handle(): int
{
    $hours     = max(1, min(168, (int) $this->option('hours')));
    $domains   = $this->resolveDomains((string) $this->option('domains'));
    $chunkSize = max(1, (int) $this->option('chunk-size'));

    $start        = Carbon::now()->utc()->subHours($hours - 1)->startOfHour();
    $endExclusive = Carbon::now()->utc()->addHour()->startOfHour();

    $hourBuckets = collect();
    $cursor      = $start->copy();
    while ($cursor->lt($endExclusive)) {
        $hourBuckets->push($cursor->copy()->toIso8601String());
        $cursor->addHour();
    }

    $this->info("Backfilling {$hourBuckets->count()} hours from {$start->toIso8601String()} to {$endExclusive->toIso8601String()}");
    $this->line('Domains: '.implode(', ', $domains));

    if (in_array('commerce', $domains, true)) {
        $this->backfillCommerce($hourBuckets, $start, $endExclusive);
    }

    if (in_array('site', $domains, true)) {
        $this->backfillSite($hourBuckets, $start, $endExclusive, $chunkSize);
    }

    if (in_array('booking', $domains, true)) {
        $this->backfillBooking($hourBuckets, $start, $endExclusive, $chunkSize);
    }

    $this->info('Hourly backfill jobs dispatched.');

    return self::SUCCESS;
}
```

- [ ] **Step 3: Update `backfillSite` to chunk the dispatch**

Replace the existing `backfillSite` method:

```php
/**
 * @param  Collection<int, string>  $hourBuckets  ISO8601 strings
 */
private function backfillSite(Collection $hourBuckets, Carbon $start, Carbon $endExclusive, int $chunkSize): void
{
    $professionalIds = DB::table('analytics.site_visits')
        ->select('professional_id')
        ->whereBetween('occurred_at', [$start, $endExclusive])
        ->union(
            DB::table('analytics.link_clicks')
                ->select('professional_id')
                ->whereBetween('occurred_at', [$start, $endExclusive])
        )
        ->distinct()
        ->pluck('professional_id')
        ->map(static fn ($id): string => trim((string) $id))
        ->filter()
        ->values();

    if ($professionalIds->isEmpty()) {
        $this->line('Site backfill: no professionals found in range, skipping.');

        return;
    }

    $batchCount = 0;
    foreach ($hourBuckets as $hour) {
        $professionalIds->chunk($chunkSize)->each(function (Collection $chunk, int $chunkIndex) use ($hour, &$batchCount): void {
            $jobs = $chunk->map(
                static fn (string $id) => new RebuildSiteHourlyAggregatesJob($id, $hour)
            )->all();

            Bus::batch($jobs)
                ->name("site-hourly-backfill:{$hour}:chunk-{$chunkIndex}")
                ->allowFailures()
                ->dispatch();

            $batchCount++;
        });
    }

    $this->line("Site batches dispatched: hours={$hourBuckets->count()}, professionals={$professionalIds->count()}, batches={$batchCount}");
}
```

- [ ] **Step 4: Update `backfillBooking` to chunk the dispatch**

Replace the existing `backfillBooking` method:

```php
/**
 * @param  Collection<int, string>  $hourBuckets  ISO8601 strings
 */
private function backfillBooking(Collection $hourBuckets, Carbon $start, Carbon $endExclusive, int $chunkSize): void
{
    $professionalIds = DB::table('analytics.booking_events')
        ->select('professional_id')
        ->whereBetween('occurred_at', [$start, $endExclusive])
        ->distinct()
        ->pluck('professional_id')
        ->map(static fn ($id): string => trim((string) $id))
        ->filter()
        ->values();

    if ($professionalIds->isEmpty()) {
        $this->line('Booking backfill: no professionals found in range, skipping.');

        return;
    }

    $batchCount = 0;
    foreach ($hourBuckets as $hour) {
        $professionalIds->chunk($chunkSize)->each(function (Collection $chunk, int $chunkIndex) use ($hour, &$batchCount): void {
            $jobs = $chunk->map(
                static fn (string $id) => new RebuildBookingHourlyAggregatesJob($id, $hour)
            )->all();

            Bus::batch($jobs)
                ->name("booking-hourly-backfill:{$hour}:chunk-{$chunkIndex}")
                ->allowFailures()
                ->dispatch();

            $batchCount++;
        });
    }

    $this->line("Booking batches dispatched: hours={$hourBuckets->count()}, professionals={$professionalIds->count()}, batches={$batchCount}");
}
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
composer test -- --filter BackfillHourlyAnalyticsCommandTest
```

Expected: All 5 tests pass.

- [ ] **Step 6: Run full test suite to check for regressions**

```bash
composer test
```

Expected: Same number of failures as before this change (4 pre-existing failures unrelated to analytics).

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/BackfillHourlyAnalytics.php \
        app/Jobs/Analytics/RebuildSiteHourlyAggregatesJob.php \
        app/Jobs/Analytics/RebuildBookingHourlyAggregatesJob.php \
        tests/Feature/Analytics/BackfillHourlyAnalyticsCommandTest.php
git commit -m "feat(analytics): chunk backfill batch dispatch to cap per-batch professional count"
```

---

## Notes

- The `--chunk-size` default of 500 is a safe starting point: at 500 professionals × 1 job object (~1 KB serialized) = ~500 KB per batch dispatch call, well within PHP memory limits.
- Batch names include `:chunk-N` so Horizon's batch list stays navigable — you'll see `site-hourly-backfill:2026-04-20T10:00:00+00:00:chunk-0`, `chunk-1`, etc.
- The empty-collection early return is new — previously a zero-professional backfill would silently dispatch zero-job batches, which is harmless but wasteful.
- The 4 pre-existing test failures (`RequestValidationTest`, Shopify design token test) are unrelated and should not change with this implementation.
