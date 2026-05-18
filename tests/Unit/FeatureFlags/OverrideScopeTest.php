<?php

use App\Services\FeatureFlags\OverrideScope;

it('builds a professional scope', function () {
    $scope = OverrideScope::forProfessional('pro-uuid-1');
    expect($scope->professionalId)->toBe('pro-uuid-1');
    expect($scope->brandId)->toBeNull();
});

it('builds a brand scope', function () {
    $scope = OverrideScope::forBrand('brand-uuid-1');
    expect($scope->brandId)->toBe('brand-uuid-1');
    expect($scope->professionalId)->toBeNull();
});

it('rejects scopes with neither id set', function () {
    OverrideScope::forProfessional('');
})->throws(InvalidArgumentException::class);
