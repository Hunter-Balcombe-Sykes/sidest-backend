<?php

namespace App\Services\Store;

// V2: Simplified. Commission rate defaults and effective rate calculation. Per-product overrides now live in Shopify metafields instead of local tables.
class BrandPricingService
{
    public function defaultCommissionRate(): float
    {
        return (float) config('comet.store.default_commission_rate', 15);
    }

    public function effectiveCommissionRate(?float $commissionOverride, ?float $defaultCommissionRate): float
    {
        $rate = $commissionOverride ?? $defaultCommissionRate ?? $this->defaultCommissionRate();

        return round(max(0.0, min(100.0, (float) $rate)), 2);
    }
}
