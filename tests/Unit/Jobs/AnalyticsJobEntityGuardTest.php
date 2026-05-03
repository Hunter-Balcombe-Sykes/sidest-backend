<?php

use App\Jobs\Analytics\RebuildBookingDailyAggregatesJob;
use App\Jobs\Analytics\RebuildBookingHourlyAggregatesJob;
use App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob;
use App\Jobs\Analytics\RebuildCommerceHourlyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteDailyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteHourlyAggregatesJob;
use App\Services\Analytics\BookingAnalyticsAggregateService;
use App\Services\Analytics\CommerceAnalyticsAggregateService;
use App\Services\Analytics\SiteAnalyticsAggregateService;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
});

// ── Site Hourly ──────────────────────────────────────────────────────────────

it('RebuildSiteHourlyAggregatesJob skips when professional does not exist', function () {
    $svc = Mockery::mock(SiteAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildProfessionalHour');

    $job = new RebuildSiteHourlyAggregatesJob((string) Str::uuid(), '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

it('RebuildSiteHourlyAggregatesJob runs when professional exists', function () {
    $pro = createTenant('site-hourly-pro');

    $svc = Mockery::mock(SiteAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildProfessionalHour')->once();

    $job = new RebuildSiteHourlyAggregatesJob($pro->id, '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

// ── Site Daily ───────────────────────────────────────────────────────────────

it('RebuildSiteDailyAggregatesJob skips when professional does not exist', function () {
    $svc = Mockery::mock(SiteAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildProfessionalDay');

    $job = new RebuildSiteDailyAggregatesJob((string) Str::uuid(), '2026-04-24');
    $job->handle($svc);
});

it('RebuildSiteDailyAggregatesJob runs when professional exists', function () {
    $pro = createTenant('site-daily-pro');

    $svc = Mockery::mock(SiteAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildProfessionalDay')->once();

    $job = new RebuildSiteDailyAggregatesJob($pro->id, '2026-04-24');
    $job->handle($svc);
});

// ── Commerce Daily ───────────────────────────────────────────────────────────

it('RebuildCommerceDailyAggregatesJob skips when brand does not exist', function () {
    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildForOrder');

    $affiliate = createAffiliateTenant('commerce-daily-aff');

    $job = new RebuildCommerceDailyAggregatesJob((string) Str::uuid(), $affiliate->id, '2026-04-24');
    $job->handle($svc);
});

it('RebuildCommerceDailyAggregatesJob skips when affiliate does not exist', function () {
    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildForOrder');

    $brand = createBrandTenant('commerce-daily-brand');

    $job = new RebuildCommerceDailyAggregatesJob($brand->id, (string) Str::uuid(), '2026-04-24');
    $job->handle($svc);
});

it('RebuildCommerceDailyAggregatesJob runs when both professionals exist', function () {
    $brand = createBrandTenant('commerce-daily-b2');
    $affiliate = createAffiliateTenant('commerce-daily-a2');

    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildForOrder')->once();

    $job = new RebuildCommerceDailyAggregatesJob($brand->id, $affiliate->id, '2026-04-24');
    $job->handle($svc);
});

// ── Commerce Hourly ──────────────────────────────────────────────────────────

it('RebuildCommerceHourlyAggregatesJob skips when brand does not exist', function () {
    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildForHour');

    $affiliate = createAffiliateTenant('commerce-hourly-aff');

    $job = new RebuildCommerceHourlyAggregatesJob((string) Str::uuid(), $affiliate->id, '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

it('RebuildCommerceHourlyAggregatesJob runs when both professionals exist', function () {
    $brand = createBrandTenant('commerce-hourly-b2');
    $affiliate = createAffiliateTenant('commerce-hourly-a2');

    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildForHour')->once();

    $job = new RebuildCommerceHourlyAggregatesJob($brand->id, $affiliate->id, '2026-04-24T00:00:00Z');
    $job->handle($svc);
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
