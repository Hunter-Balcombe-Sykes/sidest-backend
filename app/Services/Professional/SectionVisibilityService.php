<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\Log;

// V2: Validates section visibility requirements. Gallery needs 1+ images; booking needs 1+ service AND a booking integration or link; services needs 1+ service with a title + price > 0.
class SectionVisibilityService
{
    /**
     * Check if a section type meets its visibility requirements.
     *
     * @return array{0: bool, 1: ?string} [canBeVisible, reason]
     */
    public function checkVisibilityRequirements(
        string $professionalId,
        string $siteId,
        string $blockType
    ): array {
        return match ($blockType) {
            'gallery' => $this->checkGalleryRequirements($siteId),
            'booking' => $this->checkBookingRequirements($professionalId),
            'services' => $this->checkServicesRequirements($professionalId),
            default => [true, null],
        };
    }

    /**
     * Re-evaluate and persist is_enabled for a section block based on its requirements.
     * is_active (the professional's show/hide preference) is never touched.
     */
    public function reevaluateEnabled(string $professionalId, string $siteId, string $blockType): void
    {
        $block = Block::query()
            ->where('professional_id', $professionalId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', $blockType)
            ->first();

        if (! $block) {
            return;
        }

        try {
            [$canBeEnabled] = $this->checkVisibilityRequirements($professionalId, $siteId, $blockType);

            if ((bool) $block->is_enabled !== $canBeEnabled) {
                $block->is_enabled = $canBeEnabled;
                $block->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Section is_enabled reevaluation failed', [
                'professional_id' => $professionalId,
                'site_id' => $siteId,
                'block_type' => $blockType,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function checkGalleryRequirements(string $siteId): array
    {
        $hasImage = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasImage) {
            return [false, 'Gallery section requires at least 1 uploaded image.'];
        }

        return [true, null];
    }

    private function checkServicesRequirements(string $professionalId): array
    {
        // The Services & Pricing section is publishable when the professional
        // has at least one active, non-deleted service with a title and a
        // price > 0. Matches the "valid enough to show publicly" bar used by
        // the dashboard's service editor — title and price are the two
        // fields customers actually see in the rendered list.
        $hasPricedService = Service::query()
            ->where('professional_id', $professionalId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->where('price_cents', '>', 0)
            ->exists();

        if (! $hasPricedService) {
            return [false, 'Services section requires at least 1 service with a title and price.'];
        }

        return [true, null];
    }

    private function checkBookingRequirements(string $professionalId): array
    {
        $hasService = Service::query()
            ->where('professional_id', $professionalId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasService) {
            return [false, 'Booking section requires at least 1 active service.'];
        }

        $hasBookingIntegration = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->whereIn('provider', [
                ProfessionalIntegration::PROVIDER_SQUARE,
                ProfessionalIntegration::PROVIDER_FRESHA,
            ])
            ->exists();

        $hasBookingLink = Block::query()
            ->where('professional_id', $professionalId)
            ->where('block_group', 'sections')
            ->where('block_type', 'booking')
            ->whereRaw("NULLIF(BTRIM(settings->>'booking_url'), '') IS NOT NULL")
            ->exists();

        if (! $hasBookingIntegration && ! $hasBookingLink) {
            return [false, 'Booking section requires a booking link or booking integration.'];
        }

        return [true, null];
    }
}
