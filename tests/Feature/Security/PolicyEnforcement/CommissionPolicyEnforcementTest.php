<?php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Services\Store\BrandAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupCommissionPayoutsTable();

    // Default: BrandAccessService denies all capability checks.
    // Individual tests override when they need a capability granted.
    $this->mock(BrandAccessService::class, function ($mock) {
        $mock->shouldReceive('canReadBrandFinancialAnalytics')->andReturn(false)->byDefault();
        $mock->shouldReceive('canManageBrand')->andReturn(false)->byDefault();
    });
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Insert a commission_payout row and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createPayout(string $brandId, string $affiliateId, array $overrides = []): CommissionPayout
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'pending',
        'net_payout_cents' => 5000,
        'currency_code' => 'GBP',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return CommissionPayout::query()->findOrFail($id);
}

// ---------------------------------------------------------------------------
// view — allow cases
// ---------------------------------------------------------------------------

it('allows the brand owner to view a commission payout via Gate', function () {
    $brand = createBrandTenant('brand-cp-view');
    $affiliate = createAffiliateTenant('aff-cp-view');
    $payout = createPayout($brand->id, $affiliate->id);

    $response = Gate::forUser($brand)->inspect('view', $payout);

    expect($response->allowed())->toBeTrue();
});

it('allows the affiliate to view their own commission payout via Gate', function () {
    $brand = createBrandTenant('brand-cp-aff');
    $affiliate = createAffiliateTenant('aff-cp-aff');
    $payout = createPayout($brand->id, $affiliate->id);

    $response = Gate::forUser($affiliate)->inspect('view', $payout);

    expect($response->allowed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// view — deny cases
// ---------------------------------------------------------------------------

it('denies a third party from viewing a commission payout with 404 via Gate', function () {
    $brand = createBrandTenant('brand-cp-deny');
    $affiliate = createAffiliateTenant('aff-cp-deny');
    $stranger = createAffiliateTenant('stranger-cp');
    $payout = createPayout($brand->id, $affiliate->id);

    $response = Gate::forUser($stranger)->inspect('view', $payout);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// update — allow cases
// ---------------------------------------------------------------------------

it('allows the brand owner to update a commission payout via Gate', function () {
    $brand = createBrandTenant('brand-cp-upd');
    $affiliate = createAffiliateTenant('aff-cp-upd');
    $payout = createPayout($brand->id, $affiliate->id);

    $response = Gate::forUser($brand)->inspect('update', $payout);

    expect($response->allowed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// update — deny cases
// ---------------------------------------------------------------------------

it('denies the affiliate from updating their own payout with 404 via Gate', function () {
    $brand = createBrandTenant('brand-cp-aff-upd');
    $affiliate = createAffiliateTenant('aff-cp-aff-upd');
    $payout = createPayout($brand->id, $affiliate->id);

    // Affiliate has no canManageBrand capability (mock default returns false)
    $response = Gate::forUser($affiliate)->inspect('update', $payout);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('denies a pending-deletion brand owner from updating a payout with 423 via Gate', function () {
    $brand = createBrandTenant('brand-cp-pending');
    $affiliate = createAffiliateTenant('aff-cp-pending');
    $payout = createPayout($brand->id, $affiliate->id);

    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $brand->id)
        ->update(['status' => 'pending_deletion']);
    $brand->refresh();

    $response = Gate::forUser($brand)->inspect('update', $payout);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
