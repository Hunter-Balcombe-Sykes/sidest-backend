<?php

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

// Defense-in-depth assertion for SEC-3. The embedded controllers route every
// write through $this->authorizeForUser($pro, ABILITY, $resource); this sweep
// confirms the Policy gates that wire up to those calls actually reject when
// the actor doesn't own the resource — so a future endpoint that loads a
// resource from a non-attribute source still trips the gate.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBrandStoreSettingsTable();
    setupBrandProfilesTable();
    setupProfessionalIntegrationsTable();

    // IntegrationPolicy::manage falls through to BrandAccessService when
    // actor != owner, which queries brand-team tables. Force it to return
    // false so the gate denies on ownership alone — the scenario we want
    // to assert.
    $this->mock(BrandAccessService::class, function ($mock) {
        $mock->shouldReceive('canManageShopify')->andReturn(false);
        $mock->shouldReceive('isBrandProfessional')->andReturn(true);
    });
});

it('BrandResourcePolicy::update denies a cross-tenant BrandStoreSettings write', function () {
    [$brandA, $brandB] = createTwoTenants('brand');

    $settings = new BrandStoreSettings(['professional_id' => $brandB->id]);

    expect(fn () => Gate::forUser($brandA)->authorize('update', $settings))
        ->toThrow(AuthorizationException::class);
});

it('BrandResourcePolicy::update denies a cross-tenant BrandProfile write', function () {
    [$brandA, $brandB] = createTwoTenants('brand');

    $profile = new BrandProfile(['professional_id' => $brandB->id]);

    expect(fn () => Gate::forUser($brandA)->authorize('update', $profile))
        ->toThrow(AuthorizationException::class);
});

it('IntegrationPolicy::manage denies a cross-tenant ProfessionalIntegration write', function () {
    [$brandA, $brandB] = createTwoTenants('brand');

    $integration = new ProfessionalIntegration([
        'professional_id' => $brandB->id,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
    ]);

    expect(fn () => Gate::forUser($brandA)->authorize('manage', $integration))
        ->toThrow(AuthorizationException::class);
});

it('BrandResourcePolicy::update allows a self-owned BrandStoreSettings write', function () {
    [$brand] = createTwoTenants('brand');

    $settings = new BrandStoreSettings(['professional_id' => $brand->id]);

    // No throw — the gate permits the write because actor owns the resource.
    Gate::forUser($brand)->authorize('update', $settings);

    expect(true)->toBeTrue();
});

it('IntegrationPolicy::manage allows a self-owned ProfessionalIntegration write', function () {
    [$brand] = createTwoTenants('brand');

    $integration = new ProfessionalIntegration([
        'professional_id' => $brand->id,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
    ]);

    Gate::forUser($brand)->authorize('manage', $integration);

    expect(true)->toBeTrue();
});
