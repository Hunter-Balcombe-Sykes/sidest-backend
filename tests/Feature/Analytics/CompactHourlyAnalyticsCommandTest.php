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

    $this->artisan('sidest:analytics:compact-hourly', ['--domains' => 'site', '--chunk-size' => 500])
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

    $this->artisan('sidest:analytics:compact-hourly', ['--domains' => 'site', '--chunk-size' => 500])
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

    $this->artisan('sidest:analytics:compact-hourly', ['--domains' => 'site', '--dry-run' => true])
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

    $this->artisan('sidest:analytics:compact-hourly', ['--domains' => 'booking', '--chunk-size' => 500])
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

    $this->artisan('sidest:analytics:compact-hourly', ['--domains' => 'booking', '--dry-run' => true])
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

    $this->artisan('sidest:analytics:compact-hourly', ['--domains' => 'commerce', '--chunk-size' => 500])
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

    $this->artisan('sidest:analytics:compact-hourly', ['--domains' => 'commerce', '--chunk-size' => 500])
        ->assertSuccessful();

    // ceil(1100 / 500) = 3 batches
    Bus::assertBatchCount(3);
});

it('dispatches nothing and does not delete in commerce dry-run mode', function () {
    $brandDeleteQuery = Mockery::mock();
    $brandDeleteQuery->shouldReceive('where')->andReturnSelf();
    $brandDeleteQuery->shouldReceive('count')->andReturn(5);
    $brandDeleteQuery->shouldReceive('delete')->never();

    $affiliateDeleteQuery = Mockery::mock();
    $affiliateDeleteQuery->shouldReceive('where')->andReturnSelf();
    $affiliateDeleteQuery->shouldReceive('count')->andReturn(8);
    $affiliateDeleteQuery->shouldReceive('delete')->never();

    DB::shouldReceive('table')->with('analytics.brand_metrics_hourly as bmh')->never();
    DB::shouldReceive('table')->with('analytics.brand_metrics_hourly')->andReturn($brandDeleteQuery);
    DB::shouldReceive('table')->with('analytics.professional_metrics_hourly')->andReturn($affiliateDeleteQuery);
    DB::shouldReceive('scalar')->andReturn(2);

    $this->artisan('sidest:analytics:compact-hourly', ['--domains' => 'commerce', '--dry-run' => true])
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});
