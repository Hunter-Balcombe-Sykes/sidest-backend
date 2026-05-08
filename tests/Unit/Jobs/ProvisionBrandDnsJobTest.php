<?php

use App\Jobs\Cloudflare\ProvisionBrandDnsJob;
use App\Services\Cloudflare\CloudflareDnsService;
use Tests\TestCase;

use function Pest\Laravel\mock;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
});

it('upserts a DNS-only CNAME for brand professionals with a site', function () {
    $brand = createBrandTenant('evostudio');

    $dns = mock(CloudflareDnsService::class);
    $dns->shouldReceive('upsertCname')
        ->once()
        ->with('evostudio', 'shops.myshopify.com', false);

    (new ProvisionBrandDnsJob((string) $brand->id))->handle($dns);
});

it('no-ops for non-brand professionals', function () {
    $affiliate = createTenant('jane', 'influencer');

    $dns = mock(CloudflareDnsService::class);
    $dns->shouldNotReceive('upsertCname');

    (new ProvisionBrandDnsJob((string) $affiliate->id))->handle($dns);
});

it('no-ops when brand has no site row', function () {
    setupProfessionalsTable();

    $proId = (string) \Illuminate\Support\Str::uuid();
    $now = now()->toDateTimeString();

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'brandnossite',
        'handle_lc' => 'brandnossite',
        'display_name' => 'Brand No Site',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $dns = mock(CloudflareDnsService::class);
    $dns->shouldNotReceive('upsertCname');

    (new ProvisionBrandDnsJob($proId))->handle($dns);
});

it('no-ops when professional does not exist', function () {
    $dns = mock(CloudflareDnsService::class);
    $dns->shouldNotReceive('upsertCname');

    (new ProvisionBrandDnsJob('00000000-0000-0000-0000-000000000000'))->handle($dns);
});
