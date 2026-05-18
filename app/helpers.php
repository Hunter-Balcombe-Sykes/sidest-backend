<?php

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Services\FeatureFlags\FeatureFlagService;

if (! function_exists('feature')) {
    /**
     * Check whether a feature flag is enabled for an optional professional/brand context.
     * Null context falls back to the flag's default_enabled + rollout_percent.
     */
    function feature(string $key, ?Professional $pro = null, ?BrandProfile $brand = null): bool
    {
        return app(FeatureFlagService::class)->enabled($key, $pro, $brand);
    }
}
