<?php

namespace App\Services\Professional\Brand;

use App\Jobs\Store\SeedAffiliateDefaultSelectionsJob;
use App\Models\Core\Professional\BrandPartnerLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Pilot/V1: each affiliate is connected to exactly one brand partner (slot 0).
// The schema (slot CHECK 0..3, unique (affiliate, slot)) still supports up to four,
// so this cap can be relaxed by bumping MAX_ADDITIONAL_PARTNERS without a migration.
class BrandPartnerLinkService
{
    public const PRIMARY_SLOT = 0;

    public const MAX_ADDITIONAL_PARTNERS = 0;

    public function getLinksForAffiliate(string $affiliateProfessionalId): Collection
    {
        return BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->orderBy('slot')
            ->get();
    }

    public function getAffiliateIdsForBrand(string $brandProfessionalId): array
    {
        return BrandPartnerLink::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('affiliate_professional_id')
            ->all();
    }

    public function getBrandIdsForAffiliate(string $affiliateProfessionalId): array
    {
        return BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->orderBy('slot')
            ->pluck('brand_professional_id')
            ->all();
    }

    public function isConnected(string $affiliateProfessionalId, string $brandProfessionalId): bool
    {
        return BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('brand_professional_id', $brandProfessionalId)
            ->exists();
    }

    public function connectBrandToAffiliate(string $affiliateProfessionalId, string $brandProfessionalId): BrandPartnerLink
    {
        return DB::transaction(function () use ($affiliateProfessionalId, $brandProfessionalId): BrandPartnerLink {
            $links = BrandPartnerLink::query()
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->lockForUpdate()
                ->get();

            if ($links->contains(fn (BrandPartnerLink $link): bool => $link->brand_professional_id === $brandProfessionalId)) {
                throw new RuntimeException('You are already connected to this brand partner.');
            }

            $slot = $this->determineNextSlot($links);
            if ($slot === null) {
                throw new RuntimeException('You are already connected to a brand partner. Disconnect from your current brand partner before connecting to a new one.');
            }

            $link = BrandPartnerLink::query()->create([
                'affiliate_professional_id' => $affiliateProfessionalId,
                'brand_professional_id' => $brandProfessionalId,
                'slot' => $slot,
            ]);

            // Seed default product selections after the transaction commits
            SeedAffiliateDefaultSelectionsJob::dispatch($affiliateProfessionalId, $brandProfessionalId)
                ->afterCommit();

            return $link;
        });
    }

    public function disconnectBrandFromAffiliate(string $affiliateProfessionalId, string $brandProfessionalId): bool
    {
        return DB::transaction(function () use ($affiliateProfessionalId, $brandProfessionalId): bool {
            $links = BrandPartnerLink::query()
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->orderBy('slot')
                ->lockForUpdate()
                ->get();

            /** @var BrandPartnerLink|null $target */
            $target = $links->firstWhere('brand_professional_id', $brandProfessionalId);
            if (! $target) {
                return false;
            }

            $target->delete();

            $remaining = BrandPartnerLink::query()
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->orderBy('slot')
                ->lockForUpdate()
                ->get();

            if ((int) $target->slot === self::PRIMARY_SLOT) {
                /** @var BrandPartnerLink|null $newPrimary */
                $newPrimary = $remaining->first(fn (BrandPartnerLink $link): bool => (int) $link->slot > self::PRIMARY_SLOT);
                if ($newPrimary) {
                    $newPrimary->slot = self::PRIMARY_SLOT;
                    $newPrimary->save();
                }
            }

            $this->normalizeAdditionalSlots($affiliateProfessionalId);

            return true;
        });
    }

    private function determineNextSlot(Collection $links): ?int
    {
        $usedSlots = $links
            ->map(fn (BrandPartnerLink $link): int => (int) $link->slot)
            ->all();

        if (! in_array(self::PRIMARY_SLOT, $usedSlots, true)) {
            return self::PRIMARY_SLOT;
        }

        for ($slot = 1; $slot <= self::MAX_ADDITIONAL_PARTNERS; $slot++) {
            if (! in_array($slot, $usedSlots, true)) {
                return $slot;
            }
        }

        return null;
    }

    private function normalizeAdditionalSlots(string $affiliateProfessionalId): void
    {
        $links = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->orderBy('slot')
            ->lockForUpdate()
            ->get();

        $additional = $links
            ->filter(fn (BrandPartnerLink $link): bool => (int) $link->slot > self::PRIMARY_SLOT)
            ->sortBy('slot')
            ->values();

        foreach ($additional as $index => $link) {
            $expectedSlot = $index + 1;
            if ((int) $link->slot !== $expectedSlot) {
                $link->slot = $expectedSlot;
                $link->save();
            }
        }
    }

    public function getLinkForPair(string $affiliateProfessionalId, string $brandProfessionalId): ?BrandPartnerLink
    {
        return BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('brand_professional_id', $brandProfessionalId)
            ->first();
    }
}
