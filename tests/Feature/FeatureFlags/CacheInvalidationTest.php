<?php

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\FeatureFlags\OverrideScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;

beforeEach(function () {
    Cache::flush();
    FeatureFlagTestCase::boot();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'cache-test-pro',
        'display_name' => 'Cache Test Pro',
        'primary_email' => 'cache@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
    ]);
    $this->pro = Professional::find($proId);

    $brandId = (string) Str::uuid();
    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => $brandId,
        'professional_id' => $proId,
        'brand_status' => 'active',
    ]);
    $this->brand = BrandProfile::find($brandId);

    $this->service = app(FeatureFlagService::class);
    FeatureFlag::create(['key' => 'cache_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
});

it('cache hit returns same value as DB', function () {
    FeatureFlagOverride::create([
        'flag_key' => 'cache_flag',
        'professional_id' => $this->pro->id,
        'enabled' => true,
    ]);

    expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
    // Mutate DB without going through service — cache should still return true
    FeatureFlagOverride::where('flag_key', 'cache_flag')->update(['enabled' => false]);
    expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
});

it('setOverride invalidates the pro cache key', function () {
    expect($this->service->enabled('cache_flag', $this->pro))->toBeFalse();
    $this->service->setOverride('cache_flag', OverrideScope::forProfessional($this->pro->id), true, null, null);
    expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
});

it('clearOverride invalidates the pro cache key', function () {
    $this->service->setOverride('cache_flag', OverrideScope::forProfessional($this->pro->id), true, null, null);
    expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
    $this->service->clearOverride('cache_flag', OverrideScope::forProfessional($this->pro->id));
    expect($this->service->enabled('cache_flag', $this->pro))->toBeFalse();
});

it('setOverride invalidates the brand cache key', function () {
    expect($this->service->enabled('cache_flag', null, $this->brand))->toBeFalse();
    $this->service->setOverride('cache_flag', OverrideScope::forBrand($this->brand->id), true, null, null);
    expect($this->service->enabled('cache_flag', null, $this->brand))->toBeTrue();
});

it('clearOverride invalidates the brand cache key', function () {
    $this->service->setOverride('cache_flag', OverrideScope::forBrand($this->brand->id), true, null, null);
    expect($this->service->enabled('cache_flag', null, $this->brand))->toBeTrue();
    $this->service->clearOverride('cache_flag', OverrideScope::forBrand($this->brand->id));
    expect($this->service->enabled('cache_flag', null, $this->brand))->toBeFalse();
});

it('brand override takes precedence over pro override', function () {
    $this->service->setOverride('cache_flag', OverrideScope::forProfessional($this->pro->id), false, null, null);
    $this->service->setOverride('cache_flag', OverrideScope::forBrand($this->brand->id), true, null, null);
    expect($this->service->enabled('cache_flag', $this->pro, $this->brand))->toBeTrue();
});
