<?php

use App\Models\Core\Professional\BrandPartnerLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBrandLinkTables(); // creates brand.brand_partner_links
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Insert a brand.brand_partner_links row and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createPartnerLink(string $brandId, string $affiliateId, array $overrides = []): BrandPartnerLink
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('brand.brand_partner_links')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'slot' => 0,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return BrandPartnerLink::query()->findOrFail($id);
}

// ---------------------------------------------------------------------------
// view — allow cases
// ---------------------------------------------------------------------------

it('allows the brand owner to view a partner link via Gate', function () {
    $brand = createBrandTenant('brand-bpl-view');
    $affiliate = createAffiliateTenant('aff-bpl-view');
    $link = createPartnerLink($brand->id, $affiliate->id);

    $response = Gate::forUser($brand)->inspect('view', $link);

    expect($response->allowed())->toBeTrue();
});

it('allows the affiliate to view their own partner link via Gate', function () {
    $brand = createBrandTenant('brand-bpl-aff');
    $affiliate = createAffiliateTenant('aff-bpl-aff');
    $link = createPartnerLink($brand->id, $affiliate->id);

    $response = Gate::forUser($affiliate)->inspect('view', $link);

    expect($response->allowed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// view — deny case
// ---------------------------------------------------------------------------

it('denies a third party from viewing a partner link with 404 via Gate', function () {
    $brand = createBrandTenant('brand-bpl-deny');
    $affiliate = createAffiliateTenant('aff-bpl-deny');
    $stranger = createAffiliateTenant('stranger-bpl');
    $link = createPartnerLink($brand->id, $affiliate->id);

    $response = Gate::forUser($stranger)->inspect('view', $link);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// update — allow case
// ---------------------------------------------------------------------------

it('allows the brand owner to update a partner link via Gate', function () {
    $brand = createBrandTenant('brand-bpl-upd');
    $affiliate = createAffiliateTenant('aff-bpl-upd');
    $link = createPartnerLink($brand->id, $affiliate->id);

    $response = Gate::forUser($brand)->inspect('update', $link);

    expect($response->allowed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// update — deny cases
// ---------------------------------------------------------------------------

it('denies the affiliate from updating a partner link with 404 via Gate', function () {
    $brand = createBrandTenant('brand-bpl-aff-upd');
    $affiliate = createAffiliateTenant('aff-bpl-aff-upd');
    $link = createPartnerLink($brand->id, $affiliate->id);

    $response = Gate::forUser($affiliate)->inspect('update', $link);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('denies a pending-deletion brand owner from updating a partner link with 423 via Gate', function () {
    $brand = createBrandTenant('brand-bpl-pending');
    $affiliate = createAffiliateTenant('aff-bpl-pending');
    $link = createPartnerLink($brand->id, $affiliate->id);

    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $brand->id)
        ->update(['status' => 'pending_deletion']);
    $brand->refresh();

    $response = Gate::forUser($brand)->inspect('update', $link);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
