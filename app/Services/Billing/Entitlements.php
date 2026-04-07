<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;

// V2: Subscription tier checks and feature entitlement resolution. Gates premium features by plan level.
class Entitlements
{
    private const TIER_RANK = ['free' => 0, 'professional' => 1, 'brands' => 2];

    /** @var array<string, Subscription|null> Per-request cache keyed by professional ID */
    private array $cache = [];

    public function currentSubscription(Professional $professional): ?Subscription
    {
        $key = $professional->id;

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = Subscription::query()
                ->with('plan')
                ->where('professional_id', $professional->id)
                ->whereNull('ended_at')
                ->latest('created_at')
                ->first();
        }

        return $this->cache[$key];
    }

    public function clearCache(?string $professionalId = null): void
    {
        if ($professionalId) {
            unset($this->cache[$professionalId]);
        } else {
            $this->cache = [];
        }
    }

    public function hasPlan(Professional $professional, string $minPlanKey): bool
    {
        $sub = $this->currentSubscription($professional);

        if (! $sub || ! $sub->isInGracePeriod()) {
            return ($minPlanKey === 'free');
        }

        $planKey = $sub->plan?->plan_key ?? 'free';

        return (self::TIER_RANK[$planKey] ?? 0) >= (self::TIER_RANK[$minPlanKey] ?? 999);
    }

    public function hasEntitlement(Professional $professional, string $key): bool
    {
        $sub = $this->currentSubscription($professional);

        if (! $sub || ! $sub->isInGracePeriod()) {
            return false;
        }

        $ents = $sub->plan?->entitlements ?? [];

        if (! array_key_exists($key, $ents)) {
            return false;
        }

        return (bool) $ents[$key];
    }

    public function entitlementLimit(Professional $professional, string $key): ?int
    {
        $sub = $this->currentSubscription($professional);
        $ents = $sub?->plan?->entitlements ?? [];

        if (! array_key_exists($key, $ents)) {
            return null;
        }

        $value = $ents[$key];

        return is_numeric($value) ? (int) $value : null;
    }

    public function isWithinLimit(Professional $professional, string $key, int $currentCount): bool
    {
        $limit = $this->entitlementLimit($professional, $key);

        if ($limit === null) {
            return true; // no limit = unlimited
        }

        return $currentCount < $limit;
    }
}
