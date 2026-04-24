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
        '--hours' => 2,
        '--domains' => 'site',
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
        '--hours' => 1,
        '--domains' => 'site',
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
        '--hours' => 1,
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
        '--hours' => 1,
        '--domains' => 'all',
        '--chunk-size' => 500,
    ])->assertSuccessful();

    // 1 hour × 1 chunk per domain = 2 batches (site + booking; commerce is skipped/unimplemented)
    Bus::assertBatchCount(2);
    Bus::assertBatched(fn ($batch) => collect($batch->jobs)->contains(fn ($job) => $job instanceof RebuildSiteHourlyAggregatesJob));
    Bus::assertBatched(fn ($batch) => collect($batch->jobs)->contains(fn ($job) => $job instanceof RebuildBookingHourlyAggregatesJob));
});

it('dispatches no batches when no professionals exist in range', function () {
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('select')->andReturnSelf();
    DB::shouldReceive('whereBetween')->andReturnSelf();
    DB::shouldReceive('union')->andReturnSelf();
    DB::shouldReceive('distinct')->andReturnSelf();
    DB::shouldReceive('pluck')->andReturn(collect([]));

    $this->artisan('sidest:analytics:backfill-hourly', [
        '--hours' => 24,
        '--domains' => 'site',
    ])->assertSuccessful();

    Bus::assertNothingDispatched();
});
