<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\SiteMedia;

class SectionVisibilityService
{
    /**
     * Check if a section type meets its visibility requirements.
     *
     * @return array{0: bool, 1: ?string}  [canBeVisible, reason]
     */
    public function checkVisibilityRequirements(
        string $professionalId,
        string $siteId,
        string $blockType
    ): array {
        return match ($blockType) {
            'gallery' => $this->checkGalleryRequirements($siteId),
            'booking' => $this->checkBookingRequirements($professionalId),
            default   => [true, null],
        };
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
