<?php

namespace App\Policies;

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Professional\Professional;

/**
 * Defensive deny-all policy for FeatureFlag and FeatureFlagOverride.
 *
 * Real auth: the EnsurePartnaStaff middleware on the staff route group
 * (supabase.jwt + staff + staff.admin + throttle:staff). All methods here
 * return false so that a misconfigured non-staff route cannot grant access
 * to a Professional actor via Gate::forUser($pro).
 *
 * PartnaStaff actors bypass this policy entirely — the middleware is the gate.
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
