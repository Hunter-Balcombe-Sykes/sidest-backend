<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;

class Entitlements
{
    public function currentSubscription(Professional $professional): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('professional_id', $professional->id)
            ->whereNull('ended_at')
            ->latest('created_at')
            ->first();
    }

    public function hasPlan(Professional $professional, string $minPlanKey): bool
    {
        $rank = ['free' => 0, 'pro' => 1, 'elite' => 2]; // edit to match your tiers
        $sub = $this->currentSubscription($professional);

        $planKey = $sub?->plan?->plan_key ?? 'free';
        return ($rank[$planKey] ?? 0) >= ($rank[$minPlanKey] ?? 999);
    }

    public function hasEntitlement(Professional $professional, string $key): bool
    {
        $sub = $this->currentSubscription($professional);
        $ents = $sub?->plan?->entitlements ?? [];

        // supports booleans or numeric limits
        if (!array_key_exists($key, $ents)) return false;
        return (bool) $ents[$key];
    }
}
