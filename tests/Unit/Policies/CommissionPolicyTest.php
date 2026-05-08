<?php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandCommissionTopup;
use App\Models\Retail\CommissionPayout;
use App\Policies\CommissionPolicy;
use App\Services\Store\BrandAccessService;

beforeEach(function () {
    $this->brandAccess = Mockery::mock(BrandAccessService::class);
    $this->policy = new CommissionPolicy($this->brandAccess);
});

// ---------------------------------------------------------------------------
// view — CommissionPayout (has both brand and affiliate sides)
// ---------------------------------------------------------------------------

it('allows view when the actor is the affiliate on a payout', function () {
    $actor = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'professional']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    // Brand access should NOT be checked when affiliate match short-circuits
    $this->brandAccess->shouldReceive('canReadBrandFinancialAnalytics')->never();

    expect($this->policy->view($actor, $payout))->toBeTrue();
});

it('allows view when the actor is the brand owner', function () {
    $actor = (new Professional)->forceFill(['id' => 'brand-9', 'status' => 'active', 'professional_type' => 'brand']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    // Brand access should NOT be checked when owner match short-circuits
    $this->brandAccess->shouldReceive('canReadBrandFinancialAnalytics')->never();

    expect($this->policy->view($actor, $payout))->toBeTrue();
});

it('allows view when the actor has brand financial analytics capability', function () {
    $actor = (new Professional)->forceFill(['id' => 'team-1', 'status' => 'active', 'professional_type' => 'professional']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    $this->brandAccess->shouldReceive('canReadBrandFinancialAnalytics')
        ->with(Mockery::on(fn ($p) => $p->id === 'team-1'), 'brand-9')
        ->andReturn(true);

    expect($this->policy->view($actor, $payout))->toBeTrue();
});

it('denies view with 404 when actor has no claim on the payout', function () {
    $actor = (new Professional)->forceFill(['id' => 'nobody', 'status' => 'active', 'professional_type' => 'professional']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    $this->brandAccess->shouldReceive('canReadBrandFinancialAnalytics')
        ->with(Mockery::on(fn ($p) => $p->id === 'nobody'), 'brand-9')
        ->andReturn(false);

    $result = $this->policy->view($actor, $payout);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->denied())->toBeTrue();
    expect($result->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// update — CommissionPayout
// ---------------------------------------------------------------------------

it('allows update when the actor is the brand owner', function () {
    $actor = (new Professional)->forceFill(['id' => 'brand-9', 'status' => 'active', 'professional_type' => 'brand']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    $this->brandAccess->shouldReceive('canManageBrand')->never();

    expect($this->policy->update($actor, $payout))->toBeTrue();
});

it('allows update when the actor has canManageBrand capability', function () {
    $actor = (new Professional)->forceFill(['id' => 'team-1', 'status' => 'active', 'professional_type' => 'professional']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    $this->brandAccess->shouldReceive('canManageBrand')
        ->with(Mockery::on(fn ($p) => $p->id === 'team-1'), 'brand-9')
        ->andReturn(true);

    expect($this->policy->update($actor, $payout))->toBeTrue();
});

it('denies update with 404 when actor is the affiliate (read-only role)', function () {
    $actor = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'professional']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    $this->brandAccess->shouldReceive('canManageBrand')
        ->with(Mockery::on(fn ($p) => $p->id === 'aff-1'), 'brand-9')
        ->andReturn(false);

    $result = $this->policy->update($actor, $payout);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->denied())->toBeTrue();
    expect($result->status())->toBe(404);
});

it('denies update with 423 when actor is pending deletion', function () {
    $actor = (new Professional)->forceFill(['id' => 'brand-9', 'status' => 'pending_deletion', 'professional_type' => 'brand']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    $result = $this->policy->update($actor, $payout);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('denies update with 404 when actor has no claim', function () {
    $actor = (new Professional)->forceFill(['id' => 'nobody', 'status' => 'active', 'professional_type' => 'professional']);
    $payout = (new CommissionPayout)->forceFill(['brand_professional_id' => 'brand-9', 'affiliate_professional_id' => 'aff-1']);

    $this->brandAccess->shouldReceive('canManageBrand')
        ->with(Mockery::on(fn ($p) => $p->id === 'nobody'), 'brand-9')
        ->andReturn(false);

    $result = $this->policy->update($actor, $payout);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->denied())->toBeTrue();
    expect($result->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// view — BrandCommissionTopup (no affiliate_professional_id)
// ---------------------------------------------------------------------------

it('allows view on a BrandCommissionTopup when actor is the brand owner', function () {
    $actor = (new Professional)->forceFill(['id' => 'brand-9', 'status' => 'active', 'professional_type' => 'brand']);
    $topup = new BrandCommissionTopup(['brand_professional_id' => 'brand-9']);

    // No affiliate_professional_id on topups, so canReadBrandFinancialAnalytics
    // is only reached if owner check fails
    $this->brandAccess->shouldReceive('canReadBrandFinancialAnalytics')->never();

    expect($this->policy->view($actor, $topup))->toBeTrue();
});

it('denies view on a BrandCommissionTopup with 404 when actor has no claim and lacks capability', function () {
    $actor = (new Professional)->forceFill(['id' => 'nobody', 'status' => 'active', 'professional_type' => 'professional']);
    $topup = new BrandCommissionTopup(['brand_professional_id' => 'brand-9']);

    $this->brandAccess->shouldReceive('canReadBrandFinancialAnalytics')
        ->with(Mockery::on(fn ($p) => $p->id === 'nobody'), 'brand-9')
        ->andReturn(false);

    $result = $this->policy->view($actor, $topup);

    expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
    expect($result->denied())->toBeTrue();
    expect($result->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// viewProjections — BrandAffiliateRollup skeleton (affiliate self-access)
// ---------------------------------------------------------------------------

it('allows a professional to view their own projections', function () {
    $pro = (new Professional)->forceFill(['id' => '11111111-1111-1111-1111-111111111111']);
    $skeleton = (new \App\Models\Commerce\BrandAffiliateRollup)->forceFill(['affiliate_professional_id' => $pro->id]);

    expect($this->policy->viewProjections($pro, $skeleton))->toBeTrue();
});

it('denies a professional from viewing another professional projections', function () {
    $pro = (new Professional)->forceFill(['id' => '11111111-1111-1111-1111-111111111111']);
    $skeleton = (new \App\Models\Commerce\BrandAffiliateRollup)->forceFill(['affiliate_professional_id' => '22222222-2222-2222-2222-222222222222']);

    expect($this->policy->viewProjections($pro, $skeleton))->toBeFalse();
});
