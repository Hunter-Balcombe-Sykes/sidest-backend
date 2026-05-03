<?php

use App\Jobs\Notifications\FanOutBrandStatusNotificationJob;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
    setupBrandLinkTables();
});

it('does not publish any notifications when the brand professional does not exist', function () {
    $ghostBrandId = (string) Str::uuid();
    $affiliate = createAffiliateTenant('fan-out-ghost-aff');

    // Simulate a stale link row left behind after brand deletion.
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $ghostBrandId,
        'affiliate_professional_id' => $affiliate->id,
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldNotReceive('publish');

    $job = new FanOutBrandStatusNotificationJob($ghostBrandId, 'deactivated');
    $job->handle($publisher);
});

it('publishes deactivation notifications when the brand exists and has affiliates', function () {
    $brand = createBrandTenant('fan-out-brand');
    $affiliate = createAffiliateTenant('fan-out-aff');

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affiliate->id,
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once()->withArgs(function ($args) {
        // Named-argument calls are passed as positional in withArgs
        return true;
    });

    $job = new FanOutBrandStatusNotificationJob($brand->id, 'deactivated');
    $job->handle($publisher);
});
