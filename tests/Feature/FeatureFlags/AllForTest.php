<?php

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Services\FeatureFlags\FeatureFlagService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;

uses(Tests\TestCase::class);

beforeEach(function () {
    Cache::flush();
    FeatureFlagTestCase::boot();
    $this->service = app(FeatureFlagService::class);
});

it('returns global defaults map when no pro is passed', function () {
    FeatureFlag::create(['key' => 'a', 'default_enabled' => true, 'rollout_percent' => 0]);
    FeatureFlag::create(['key' => 'b', 'default_enabled' => false, 'rollout_percent' => 0]);

    expect($this->service->allFor())->toBe(['a' => true, 'b' => false]);
});

it('applies pro and brand overrides correctly in the map', function () {
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'all-for-pro',
        'display_name' => 'All For Pro',
        'primary_email' => 'allfor@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
    ]);
    $pro = Professional::find($proId);

    $brandId = (string) Str::uuid();
    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => $brandId,
        'professional_id' => $proId,
        'brand_status' => 'active',
    ]);
    $brand = BrandProfile::find($brandId);

    FeatureFlag::create(['key' => 'a', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlag::create(['key' => 'b', 'default_enabled' => true, 'rollout_percent' => 0]);

    FeatureFlagOverride::create(['flag_key' => 'a', 'professional_id' => $pro->id, 'enabled' => true]);
    FeatureFlagOverride::create(['flag_key' => 'b', 'brand_id' => $brand->id, 'enabled' => false]);

    expect($this->service->allFor($pro, $brand))->toBe(['a' => true, 'b' => false]);
});
