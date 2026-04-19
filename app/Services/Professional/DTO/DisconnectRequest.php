<?php

namespace App\Services\Professional\DTO;

use App\Models\Core\Professional\Professional;
use App\Services\Professional\Enums\CommissionHandling;
use App\Services\Professional\Enums\DisconnectActor;
use LogicException;

// Input to BrandPartnerLinkLifecycleService::disconnect.
// Static factories enforce actor-specific invariants at construction.
final class DisconnectRequest
{
    public function __construct(
        public readonly Professional $brand,
        public readonly Professional $affiliate,
        public readonly DisconnectActor $actor,
        public readonly ?string $reason,
        public readonly CommissionHandling $commissions,
        public readonly ?string $staffUserId,
    ) {
        if ($actor === DisconnectActor::Staff && $staffUserId === null) {
            throw new LogicException('Staff disconnect requires a staff user id.');
        }
        if ($actor !== DisconnectActor::Staff && $commissions === CommissionHandling::Void) {
            throw new LogicException('Only staff may void pending commissions on disconnect.');
        }
    }

    public static function forStaff(
        Professional $brand,
        Professional $affiliate,
        string $reason,
        CommissionHandling $commissions,
        string $staffUserId,
    ): self {
        return new self($brand, $affiliate, DisconnectActor::Staff, $reason, $commissions, $staffUserId);
    }

    public static function forBrand(
        Professional $brand,
        Professional $affiliate,
        ?string $reason,
    ): self {
        return new self($brand, $affiliate, DisconnectActor::Brand, $reason, CommissionHandling::Keep, null);
    }

    public static function forAffiliate(
        Professional $brand,
        Professional $affiliate,
        ?string $reason,
    ): self {
        return new self($brand, $affiliate, DisconnectActor::Affiliate, $reason, CommissionHandling::Keep, null);
    }
}
