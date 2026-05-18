<?php

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\FeatureFlags\FeatureFlagService;
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
        'handle' => 'degraded-pro',
        'display_name' => 'Degraded Pro',
        'primary_email' => 'degraded@example.com',
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

    // Build a service whose cache layer always throws, forcing the DB fallback path.
    $failingCache = Mockery::mock(CacheLockService::class);
    $failingCache->shouldReceive('rememberLocked')->andThrow(new \RuntimeException('Redis unavailable'));
    $this->degradedService = new FeatureFlagService($failingCache);

    FeatureFlag::create(['key' => 'deg_flag', 'default_enabled' => true, 'rollout_percent' => 0]);
});

it('enabled() falls back to DB and returns global default when cache is unavailable', function () {
    expect($this->degradedService->enabled('deg_flag'))->toBeTrue();
});

it('enabled() falls back to DB and applies pro override when cache is unavailable', function () {
    FeatureFlagOverride::create([
        'flag_key' => 'deg_flag',
        'professional_id' => $this->pro->id,
        'enabled' => false,
    ]);

    expect($this->degradedService->enabled('deg_flag', $this->pro))->toBeFalse();
});

it('enabled() falls back to DB and applies brand override when cache is unavailable', function () {
    FeatureFlag::create(['key' => 'brand_deg_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlagOverride::create([
        'flag_key' => 'brand_deg_flag',
        'brand_id' => $this->brand->id,
        'enabled' => true,
    ]);

    expect($this->degradedService->enabled('brand_deg_flag', null, $this->brand))->toBeTrue();
});

it('allFor() falls back to allForFromDb() and returns full map when cache is unavailable', function () {
    FeatureFlag::create(['key' => 'deg_flag_b', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlagOverride::create([
        'flag_key' => 'deg_flag',
        'professional_id' => $this->pro->id,
        'enabled' => false,
    ]);

    $result = $this->degradedService->allFor($this->pro);

    expect($result)->toHaveKeys(['deg_flag', 'deg_flag_b']);
    expect($result['deg_flag'])->toBeFalse(); // pro override wins
    expect($result['deg_flag_b'])->toBeFalse(); // default
});

it('enabled() degraded path returns config fallback for unknown flag key', function () {
    config(['partna.features.unknown_deg' => true]);

    expect($this->degradedService->enabled('unknown_deg'))->toBeTrue();
});
