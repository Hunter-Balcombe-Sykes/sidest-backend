<?php

namespace App\Services\FeatureFlags;

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;

/**
 * Resolves feature flag state for a given professional/brand context.
 *
 * Resolution order (highest → lowest precedence):
 *   1. Brand-scoped override (if brand passed)
 *   2. Professional-scoped override
 *   3. Percentage rollout — deterministic hash(key + pro.id) % 100
 *   4. Registry default (feature_flags.default_enabled)
 *   5. Config fallback — config('partna.features.{key}', false)
 */
class FeatureFlagService
{
    public function enabled(string $key, ?Professional $pro = null, ?BrandProfile $brand = null): bool
    {
        // 1. Brand override (if brand passed) — most specific, wins over everything.
        if ($brand !== null) {
            $brandOverride = $this->lookupOverride($key, brandId: $brand->id);
            if ($brandOverride !== null) {
                return $brandOverride;
            }
        }

        // 2. Professional override.
        if ($pro !== null) {
            $proOverride = $this->lookupOverride($key, professionalId: $pro->id);
            if ($proOverride !== null) {
                return $proOverride;
            }
        }

        $flag = FeatureFlag::find($key);

        // 3. Percentage rollout — deterministic: same pro+key always lands in the same bucket.
        //    abs() guards against negative crc32 values on 64-bit PHP.
        if ($flag !== null && $pro !== null && $flag->rollout_percent > 0) {
            $bucket = abs(crc32($key . $pro->id)) % 100;
            if ($bucket < $flag->rollout_percent) {
                return true;
            }
        }

        // 4. Global registry default.
        if ($flag !== null) {
            return $flag->default_enabled;
        }

        // 5. Config fallback — used for flags that don't yet have a DB row.
        return (bool) config('partna.features.' . $key, false);
    }

    /**
     * Reset any in-memory state. No-op in this DB-only resolver;
     * the caching layer will override this in Task 5.
     */
    public function flush(): void
    {
        // No-op — DB-only resolver reads fresh on every call.
    }

    /**
     * Look up a non-expired override for either a brand or a professional.
     * Returns the enabled boolean, or null if no matching row exists.
     */
    private function lookupOverride(string $key, ?string $professionalId = null, ?string $brandId = null): ?bool
    {
        $query = FeatureFlagOverride::where('flag_key', $key)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if ($brandId !== null) {
            // Brand-scoped row: brand_id set (professional_id may be null or set).
            $query->where('brand_id', $brandId);
        } else {
            // Pro-scoped row: professional_id set, brand_id must be null.
            $query->where('professional_id', $professionalId)->whereNull('brand_id');
        }

        $row = $query->first();

        return $row?->enabled;
    }
}
