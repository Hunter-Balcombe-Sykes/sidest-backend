<?php

use App\Models\Core\Professional\Professional;
use App\Policies\CommissionPolicy;
use App\Services\Store\BrandAccessService;

beforeEach(function () {
    $this->brandAccess = Mockery::mock(BrandAccessService::class);
    $this->policy = new CommissionPolicy($this->brandAccess);
});

it('allows a brand to topUp on themselves', function () {
    $brand = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
    expect($this->policy->topUp($brand, $brand))->toBeTrue();
});

it('forbids an affiliate from topping up another professional', function () {
    $affiliate = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'affiliate']);
    $brand = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
    expect($this->policy->topUp($affiliate, $brand))->toBeFalse();
});

it('allows brand to managePaymentMethod on self only', function () {
    $brand = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
    $other = (new Professional)->forceFill(['id' => 'brand-2', 'status' => 'active', 'professional_type' => 'brand']);
    expect($this->policy->managePaymentMethod($brand, $brand))->toBeTrue();
    expect($this->policy->managePaymentMethod($brand, $other))->toBeFalse();
});

it('forbids non-brand professional_types from managePaymentMethod', function () {
    $aff = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'affiliate']);
    expect($this->policy->managePaymentMethod($aff, $aff))->toBeFalse();
});
