<?php

namespace App\Policies;

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional\Professional;

/**
 * V2: Authorization gate for FeatureFlag and FeatureFlagOverride.
 *
 * These models are exclusively managed via staff routes, which are already
 * guarded by the EnsurePartnaStaff middleware (PartnaStaff auth — separate
 * surface from Professional). This policy denies Professional actors so that
 * a misconfigured non-staff route cannot leak access.
 *
 * Registered for both FeatureFlag and FeatureFlagOverride in AppServiceProvider.
 */
class FeatureFlagPolicy extends BasePolicy
{
    public function viewAny(Professional $pro): bool
    {
        return false;
    }

    public function view(Professional $pro, FeatureFlag|FeatureFlagOverride $resource): bool
    {
        return false;
    }

    public function manage(Professional $pro, FeatureFlag|FeatureFlagOverride|null $resource = null): bool
    {
        return false;
    }
}
