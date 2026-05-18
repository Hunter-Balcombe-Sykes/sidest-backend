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

beforeEach(function () {
    Cache::flush();
    FeatureFlagTestCase::boot();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'test-pro',
        'display_name' => 'Test Pro',
        'primary_email' => 'test@example.com',
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
});

it('returns global default when no override and no rollout', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => true, 'rollout_percent' => 0]);
    expect($this->service->enabled('test_flag', $this->pro))->toBeTrue();

    FeatureFlag::where('key', 'test_flag')->update(['default_enabled' => false]);
    $this->service->flushRegistry(); // registry cache must be evicted after direct DB mutation
    expect($this->service->enabled('test_flag', $this->pro))->toBeFalse();
});

it('falls back to config when flag row missing', function () {
    config(['partna.features.video_uploads' => true]);
    expect($this->service->enabled('video_uploads', $this->pro))->toBeTrue();

    config(['partna.features.video_uploads' => false]);
    expect($this->service->enabled('video_uploads', $this->pro))->toBeFalse();
});

it('pro override wins over default', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlagOverride::create([
        'flag_key' => 'test_flag',
        'professional_id' => $this->pro->id,
        'enabled' => true,
    ]);
    expect($this->service->enabled('test_flag', $this->pro))->toBeTrue();
});

it('brand override wins over pro override', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlagOverride::create([
        'flag_key' => 'test_flag',
        'professional_id' => $this->pro->id,
        'enabled' => true,
    ]);
    FeatureFlagOverride::create([
        'flag_key' => 'test_flag',
        'brand_id' => $this->brand->id,
        'enabled' => false,
    ]);
    expect($this->service->enabled('test_flag', $this->pro, $this->brand))->toBeFalse();
});

it('expired overrides are ignored', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => false, 'rollout_percent' => 0]);
    FeatureFlagOverride::create([
        'flag_key' => 'test_flag',
        'professional_id' => $this->pro->id,
        'enabled' => true,
        'expires_at' => now()->subMinute(),
    ]);
    expect($this->service->enabled('test_flag', $this->pro))->toBeFalse();
});

it('null professional resolves to global default only', function () {
    FeatureFlag::create(['key' => 'test_flag', 'default_enabled' => true, 'rollout_percent' => 0]);
    expect($this->service->enabled('test_flag'))->toBeTrue();
});
