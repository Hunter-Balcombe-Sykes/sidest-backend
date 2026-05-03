<?php

use App\Http\Controllers\Api\Professional\FreshaIntegration\FreshaIntegrationController;
use App\Services\Fresha\FreshaServiceSyncService;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        provider_metadata TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // Default: BrandAccessService denies all cross-tenant manage attempts.
    $this->mock(BrandAccessService::class, function ($mock) {
        $mock->shouldReceive('canManageShopify')->andReturn(false);
        $mock->shouldReceive('isBrandProfessional')->andReturn(false);
    });
});

it('allows the owner to disconnect their own Fresha integration', function () {
    [$a] = createTwoTenants('professional');
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'fresha',
        'external_account_id' => 'biz-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($a, [], 'POST');
    $response = app(FreshaIntegrationController::class)->disconnect($req);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a pending_deletion owner from disconnecting Fresha with 423', function () {
    [$a] = createTwoTenants('professional');
    DB::connection('pgsql')->table('core.professionals')->where('id', $a->id)->update([
        'status' => 'pending_deletion',
    ]);
    $a->refresh();

    $now = now()->toDateTimeString();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'fresha',
        'external_account_id' => 'biz-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($a, [], 'POST');

    try {
        app(FreshaIntegrationController::class)->disconnect($req);
        expect(false)->toBeTrue('Expected AuthorizationException with 423 status');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

it('blocks one tenant from triggering Fresha sync against another tenants integration', function () {
    [$a, $b] = createTwoTenants('professional');
    $now = now()->toDateTimeString();

    // Tenant A has a Fresha integration; tenant B should not be able to act on it.
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'fresha',
        'external_account_id' => 'biz-a',
        'access_token' => 'token-a',
        'refresh_token' => 'refresh-a',
        'expires_at' => now()->addHour()->toIso8601String(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Tenant B calls syncServicesNow — they have no Fresha integration of their own.
    $req = tenantRequestAs($b, [], 'POST');
    $sync = Mockery::mock(FreshaServiceSyncService::class);
    $sync->shouldReceive('syncFromFresha')->never();

    $response = app(FreshaIntegrationController::class)->syncServicesNow($req, $sync);

    // Tenant B has no integration of their own → 404 not connected, never reaches the policy.
    expect($response->getStatusCode())->toBe(404);
});
