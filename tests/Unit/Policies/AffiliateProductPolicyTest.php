<?php

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\Professional;
use App\Policies\AffiliateProductPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->policy = new AffiliateProductPolicy;

    $this->affiliate = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'professional']);
    $this->brand = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
    $this->stranger = (new Professional)->forceFill(['id' => 'other', 'status' => 'active', 'professional_type' => 'professional']);

    $this->selection = (new AffiliateProductSelection)->forceFill([
        'affiliate_professional_id' => 'aff-1',
        'brand_professional_id' => 'brand-1',
        'shopify_product_gid' => 'gid://shopify/Product/123',
    ]);
});

// ---------------------------------------------------------------------------
// view
// ---------------------------------------------------------------------------

it('allows the affiliate to view their own selection', function () {
    expect($this->policy->view($this->affiliate, $this->selection))->toBeTrue();
});

it('allows the brand to view a selection of their product', function () {
    expect($this->policy->view($this->brand, $this->selection))->toBeTrue();
});

it('denies a third party view with 404', function () {
    $result = $this->policy->view($this->stranger, $this->selection);
    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// create
// ---------------------------------------------------------------------------

it('allows the affiliate to create a selection for themselves', function () {
    $skeleton = (new AffiliateProductSelection)->forceFill(['affiliate_professional_id' => 'aff-1', 'brand_professional_id' => 'brand-1']);
    expect($this->policy->create($this->affiliate, $skeleton))->toBeTrue();
});

it('denies create for a skeleton targeting a different affiliate', function () {
    $skeleton = (new AffiliateProductSelection)->forceFill(['affiliate_professional_id' => 'other', 'brand_professional_id' => 'brand-1']);
    expect($this->policy->create($this->affiliate, $skeleton))->toBeFalse();
});

it('denies create with 423 when affiliate is pending deletion', function () {
    $this->affiliate->status = 'pending_deletion';
    $skeleton = (new AffiliateProductSelection)->forceFill(['affiliate_professional_id' => 'aff-1', 'brand_professional_id' => 'brand-1']);
    $result = $this->policy->create($this->affiliate, $skeleton);
    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

it('allows the affiliate to update their own selection', function () {
    expect($this->policy->update($this->affiliate, $this->selection))->toBeTrue();
});

it('denies the brand from updating the affiliate selection with 404', function () {
    $result = $this->policy->update($this->brand, $this->selection);
    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

it('denies update with 423 when pending deletion', function () {
    $this->affiliate->status = 'pending_deletion';
    $result = $this->policy->update($this->affiliate, $this->selection);
    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
});

// ---------------------------------------------------------------------------
// delete
// ---------------------------------------------------------------------------

it('allows the affiliate to delete their own selection', function () {
    expect($this->policy->delete($this->affiliate, $this->selection))->toBeTrue();
});

it('denies the brand from deleting the affiliate selection with 404', function () {
    $result = $this->policy->delete($this->brand, $this->selection);
    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});
