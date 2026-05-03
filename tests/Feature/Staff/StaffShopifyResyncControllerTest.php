<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffShopifyResyncController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyDataResyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        provider_metadata TEXT,
        shopify_shop_domain TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    RateLimiter::clear('shopify-resync:test-int-staff-1');
});

function makeStaffResyncProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();

    return $pro;
}

function makeStaffShopifyIntegration(Professional $professional, string $integrationId = 'test-int-staff-1'): ProfessionalIntegration
{
    $integration = new ProfessionalIntegration([
        'professional_id' => $professional->id,
        'provider' => 'shopify',
        'access_token' => 'shpat_test',
        'provider_metadata' => ['shop_domain' => 'test.myshopify.com'],
    ]);
    $integration->id = $integrationId;
    $integration->save();

    return $integration;
}

it('returns 404 when professional has no Shopify integration', function () {
    $professional = makeStaffResyncProfessional();

    $resyncService = Mockery::mock(ShopifyDataResyncService::class);
    $controller = new StaffShopifyResyncController($resyncService);

    $response = $controller->invoke(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(404);
});

it('returns 429 when rate limit is exceeded', function () {
    $professional = makeStaffResyncProfessional();
    $integration = makeStaffShopifyIntegration($professional);

    RateLimiter::hit("shopify-resync:{$integration->id}", 60);

    $resyncService = Mockery::mock(ShopifyDataResyncService::class);
    $controller = new StaffShopifyResyncController($resyncService);

    $response = $controller->invoke(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(429);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

it('returns resync result on success', function () {
    $professional = makeStaffResyncProfessional();
    makeStaffShopifyIntegration($professional);

    $resyncResult = [
        'fields_updated' => ['display_name'],
        'fields_preserved' => [],
        'jobs_dispatched' => ['brand_design'],
        'last_resynced_at' => now()->toIso8601String(),
    ];

    $resyncService = Mockery::mock(ShopifyDataResyncService::class);
    $resyncService->shouldReceive('resync')->once()->andReturn($resyncResult);

    $controller = new StaffShopifyResyncController($resyncService);
    $response = $controller->invoke(Request::create('/', 'POST'), $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['fields_updated', 'fields_preserved', 'jobs_dispatched', 'last_resynced_at']);
});

it('returns 502 when ShopifyDataResyncService throws', function () {
    $professional = makeStaffResyncProfessional();
    makeStaffShopifyIntegration($professional);

    $resyncService = Mockery::mock(ShopifyDataResyncService::class);
    $resyncService->shouldReceive('resync')->andThrow(new \RuntimeException('Bad token'));

    $controller = new StaffShopifyResyncController($resyncService);
    $response = $controller->invoke(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(502);
});
