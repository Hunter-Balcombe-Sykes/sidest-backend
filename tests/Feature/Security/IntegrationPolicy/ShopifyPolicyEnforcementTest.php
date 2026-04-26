<?php

use App\Http\Controllers\Api\Professional\ShopifyIntegration\ShopifyIntegrationController;
use App\Services\Shopify\ShopifyTeardownService;
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
        access_token TEXT,
        provider_metadata TEXT,
        status TEXT,
        expires_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        product_gid TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('blocks a pending_deletion brand from disconnecting their Shopify integration with 423', function () {
    [$a] = createTwoTenants('brand');
    DB::connection('pgsql')->table('core.professionals')->where('id', $a->id)->update([
        'status' => 'pending_deletion',
    ]);
    $a->refresh();

    $now = now()->toDateTimeString();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'access_token' => 'token-a',
        'provider_metadata' => json_encode(['shop_domain' => 'brand-a.myshopify.com']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // BrandAccessService confirms brand A can manage their own Shopify, so authz only
    // fails because of pending_deletion.
    $this->mock(BrandAccessService::class, function ($mock) use ($a) {
        $mock->shouldReceive('isBrandProfessional')->andReturn(true);
        $mock->shouldReceive('canManageShopify')
            ->andReturnUsing(fn ($pro, $brandId) => $pro->id === $a->id && $brandId === $a->id);
    });

    $req = tenantRequestAs($a, [], 'POST');

    try {
        app(ShopifyIntegrationController::class)->disconnect($req);
        expect(false)->toBeTrue('Expected AuthorizationException with 423 status');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
        expect($e->getMessage())->toBe('Account is pending deletion.');
    }
});

it('blocks brand B from disconnecting brand As Shopify integration', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'access_token' => 'token-a',
        'provider_metadata' => json_encode(['shop_domain' => 'brand-a.myshopify.com']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->mock(BrandAccessService::class, function ($mock) use ($b) {
        $mock->shouldReceive('isBrandProfessional')->andReturnUsing(fn ($pro) => $pro->id === $b->id);
        $mock->shouldReceive('canManageShopify')
            ->andReturnUsing(fn ($pro, $brandId) => $pro->id === $b->id && $brandId === $b->id);
    });

    $req = tenantRequestAs($b, [], 'POST');
    $response = app(ShopifyIntegrationController::class)->disconnect($req);

    // Brand B resolves to its own brand_professional_id (no integration there) — so
    // disconnect is idempotent against B's empty record. The key assertion: brand A's
    // record is untouched.
    expect($response->getStatusCode())->toBe(200);
    expect(DB::table('core.professional_integrations')
        ->where('professional_id', $a->id)
        ->where('provider', 'shopify')
        ->exists())->toBeTrue();
});

it('allows a brand-team member with shopify.manage capability to disconnect on behalf of the brand', function () {
    $brand = createBrandTenant('brand-z');
    $teamMember = createAffiliateTenant('team-member');
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brand->id,
        'provider' => 'shopify',
        // access_token is cast as 'encrypted' on the model — store pre-encrypted value
        // so the disconnect path's `empty($integration->access_token)` check passes.
        'access_token' => encrypt('token-z'),
        'provider_metadata' => json_encode(['shop_domain' => 'brand-z.myshopify.com']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // BrandAccessService confirms team-member has shopify.manage on brand-z.
    $this->mock(BrandAccessService::class, function ($mock) use ($teamMember, $brand) {
        $mock->shouldReceive('isBrandProfessional')->andReturnUsing(fn ($pro) => $pro->id === $brand->id);
        $mock->shouldReceive('canManageShopify')
            ->andReturnUsing(fn ($pro, $brandId) => $pro->id === $teamMember->id && $brandId === $brand->id);
    });

    // ShopifyTeardownService requires a live encrypted token — stub it out so the
    // test only proves authz passes, not that teardown executes against a real store.
    $this->mock(ShopifyTeardownService::class, function ($mock) {
        $mock->shouldReceive('teardownForIntegration')->andReturn([]);
    });

    $req = tenantRequestAs($teamMember, ['brand_professional_id' => $brand->id], 'POST');
    $response = app(ShopifyIntegrationController::class)->disconnect($req);

    expect($response->getStatusCode())->toBe(200);
});
