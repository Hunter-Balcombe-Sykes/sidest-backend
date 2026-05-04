<?php

use App\Jobs\Notifications\FanOutBrandStatusNotificationJob;
use App\Jobs\Notifications\SendBrandStatusNotificationJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
    setupBrandLinkTables();
});

it('does not dispatch child jobs when the brand professional does not exist', function () {
    Queue::fake();

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

    $job = new FanOutBrandStatusNotificationJob($ghostBrandId, 'building');
    $job->handle();

    Queue::assertNotPushed(SendBrandStatusNotificationJob::class);
});

it('dispatches one child job per affiliate when the brand exists', function () {
    Queue::fake();

    $brand = createBrandTenant('fan-out-brand');
    $affiliateA = createAffiliateTenant('fan-out-aff-a');
    $affiliateB = createAffiliateTenant('fan-out-aff-b');

    foreach ([$affiliateA->id, $affiliateB->id] as $affiliateId) {
        \Illuminate\Support\Facades\DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
            'id' => (string) Str::uuid(),
            'brand_professional_id' => $brand->id,
            'affiliate_professional_id' => $affiliateId,
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    $job = new FanOutBrandStatusNotificationJob($brand->id, 'live');
    $job->handle();

    Queue::assertPushed(SendBrandStatusNotificationJob::class, 2);
});

it('passes correct status and brand id to each child job', function () {
    Queue::fake();

    $brand = createBrandTenant('fan-out-brand-status');
    $affiliate = createAffiliateTenant('fan-out-aff-status');

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affiliate->id,
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new FanOutBrandStatusNotificationJob($brand->id, 'systems_down');
    $job->handle();

    Queue::assertPushed(SendBrandStatusNotificationJob::class, function ($job) use ($brand, $affiliate) {
        return $job->affiliateProfessionalId === $affiliate->id
            && $job->brandProfessionalId === $brand->id
            && $job->brandStatus === 'systems_down';
    });
});
