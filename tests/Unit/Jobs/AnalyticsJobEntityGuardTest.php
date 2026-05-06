<?php

use App\Jobs\Analytics\RebuildBookingDailyAggregatesJob;
use App\Jobs\Analytics\RebuildBookingHourlyAggregatesJob;
use App\Services\Analytics\BookingAnalyticsAggregateService;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
});

// ── Booking Daily ────────────────────────────────────────────────────────────

it('RebuildBookingDailyAggregatesJob skips when professional does not exist', function () {
    $svc = Mockery::mock(BookingAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildProfessionalDay');

    $job = new RebuildBookingDailyAggregatesJob((string) Str::uuid(), '2026-04-24');
    $job->handle($svc);
});

it('RebuildBookingDailyAggregatesJob runs when professional exists', function () {
    $pro = createTenant('booking-daily-pro');

    $svc = Mockery::mock(BookingAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildProfessionalDay')->once();

    $job = new RebuildBookingDailyAggregatesJob($pro->id, '2026-04-24');
    $job->handle($svc);
});

// ── Booking Hourly ───────────────────────────────────────────────────────────

it('RebuildBookingHourlyAggregatesJob skips when professional does not exist', function () {
    $svc = Mockery::mock(BookingAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildProfessionalHour');

    $job = new RebuildBookingHourlyAggregatesJob((string) Str::uuid(), '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

it('RebuildBookingHourlyAggregatesJob runs when professional exists', function () {
    $pro = createTenant('booking-hourly-pro');

    $svc = Mockery::mock(BookingAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildProfessionalHour')->once();

    $job = new RebuildBookingHourlyAggregatesJob($pro->id, '2026-04-24T00:00:00Z');
    $job->handle($svc);
});
