<?php

namespace App\Services\Store;

class BrandPricingService
{
    public function defaultCommissionRate(): float
    {
        return (float) config('comet.store.default_commission_rate', 15);
    }

    public function effectiveCommissionRate(?float $commissionOverride, ?float $defaultCommissionRate): float
    {
        $base = $commissionOverride ?? $defaultCommissionRate ?? $this->defaultCommissionRate();

        return round(max(0.0, min(100.0, (float) $base)), 2);
    }

    public function resolveBasePriceCents(?int $catalogPriceCents, mixed $customPrice): ?int
    {
        if ($customPrice !== null && $customPrice !== '' && is_numeric((string) $customPrice)) {
            return max(0, (int) round(((float) $customPrice) * 100));
        }

        if ($catalogPriceCents === null) {
            return null;
        }

        return max(0, (int) $catalogPriceCents);
    }

    public function discountedPriceCents(?int $basePriceCents, ?float $discountRate): ?int
    {
        if ($basePriceCents === null) {
            return null;
        }

        $rate = max(0.0, min(100.0, (float) ($discountRate ?? 0.0)));
        $discountedRawCents = ((float) $basePriceCents * (100.0 - $rate)) / 100.0;

        return $this->ceilToNearestFiveCents($discountedRawCents);
    }

    public function ceilToNearestFiveCents(float|int $cents): int
    {
        $amount = (float) $cents;

        if ($amount <= 0) {
            return 0;
        }

        return (int) (ceil($amount / 5.0) * 5.0);
    }
}
