<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Validates section visibility requirements. Gallery needs 1+ images; booking needs 1+ service AND a booking integration or link; services needs 1+ service with a title + price > 0.
class SectionVisibilityService
{
    /**
     * Check if a section type meets its visibility requirements.
     *
     * @param  array<string, mixed>|null  $pendingSettings  Incoming-but-not-yet-persisted settings, merged over stored
     *                                                      for block types whose requirement lives in their own payload
     *                                                      (countdown). Other block types ignore this.
     * @return array{0: bool, 1: ?string} [canBeVisible, reason]
     */
    public function checkVisibilityRequirements(
        string $professionalId,
        string $siteId,
        string $blockType,
        ?array $pendingSettings = null,
    ): array {
        return match ($blockType) {
            'gallery' => $this->checkGalleryRequirements($siteId),
            'booking' => $this->checkBookingRequirements($professionalId),
            'services' => $this->checkServicesRequirements($professionalId),
            'documents' => $this->checkDocumentsRequirements($siteId),
            'countdown' => $this->checkCountdownRequirements($professionalId, $siteId, $pendingSettings),
            'contact' => $this->checkContactRequirements($professionalId, $siteId, $pendingSettings),
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

    private function checkDocumentsRequirements(string $siteId): array
    {
        $hasDocument = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_DOCUMENTS)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasDocument) {
            return [false, 'Documents section requires an uploaded document.'];
        }

        return [true, null];
    }

    /**
     * A countdown is publishable when it has both a drop_time and an expiry_time,
     * with expiry strictly after drop, AND the expiry has not already elapsed.
     * Unlike gallery/services/documents/booking (requirements stored externally),
     * the countdown's requirement lives in its own settings — so the controller
     * passes the incoming payload through as $pendingSettings to cover the
     * first-time-publish path where the timeline and publication_state=live
     * arrive together.
     *
     * @param  array<string, mixed>|null  $pendingSettings
     * @return array{0: bool, 1: ?string}
     */
    private function checkCountdownRequirements(string $professionalId, string $siteId, ?array $pendingSettings = null): array
    {
        $block = Block::query()
            ->where('professional_id', $professionalId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', 'countdown')
            ->first();

        $stored = $block && is_array($block->settings) ? $block->settings : [];
        $settings = $pendingSettings !== null
            ? array_replace_recursive($stored, $pendingSettings)
            : $stored;

        $drop = data_get($settings, 'timeline.drop_time');
        $expiry = data_get($settings, 'timeline.expiry_time');

        if (! is_string($drop) || $drop === '') {
            return [false, 'Countdown section requires a drop time before it can go live.'];
        }

        if (! is_string($expiry) || $expiry === '') {
            return [false, 'Countdown section requires an expiry time before it can go live.'];
        }

        try {
            $dropTs = \Carbon\CarbonImmutable::parse($drop);
            $expiryTs = \Carbon\CarbonImmutable::parse($expiry);
        } catch (\Throwable) {
            return [false, 'Countdown section has an invalid drop time or expiry time.'];
        }

        if ($expiryTs->lessThanOrEqualTo($dropTs)) {
            return [false, 'Countdown expiry time must be after the drop time.'];
        }

        if ($expiryTs->isPast()) {
            return [false, 'Countdown expiry time is already in the past.'];
        }

        return [true, null];
    }

    /**
     * A contact block is publishable when it has a non-empty, valid
     * notification_email in its settings. Like countdown, the requirement
     * lives in the block's own payload — so the controller passes the
     * incoming settings as $pendingSettings to cover the first-publish
     * path (config + publication_state=live arrive together).
     *
     * @param  array<string, mixed>|null  $pendingSettings
     * @return array{0: bool, 1: ?string}
     */
    private function checkContactRequirements(string $professionalId, string $siteId, ?array $pendingSettings = null): array
    {
        $block = Block::query()
            ->where('professional_id', $professionalId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->first();

        $stored = $block && is_array($block->settings) ? $block->settings : [];
        $settings = $pendingSettings !== null
            ? array_replace_recursive($stored, $pendingSettings)
            : $stored;

        $email = data_get($settings, 'notification_email');
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [false, 'Contact section requires a notification email before it can go live.'];
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

        // Smart-booking integration path is only available when the feature flag is on.
        // Pre-launch, only the manual booking_url (redirect link) path is accepted.
        $hasBookingIntegration = (bool) config('partna.features.smart_booking', false)
            && ProfessionalIntegration::query()
                ->where('professional_id', $professionalId)
                ->whereIn('provider', [
                    ProfessionalIntegration::PROVIDER_SQUARE,
                    ProfessionalIntegration::PROVIDER_FRESHA,
                ])
                ->exists();

        if ($hasBookingIntegration) {
            return [true, null];
        }

        // Check for a booking link block — the current path. Link blocks use
        // block_group='links' with settings->category='booking'. This replaced
        // the old settings->booking_url field on the section block itself.
        $hasLinkBlock = Block::query()
            ->where('professional_id', $professionalId)
            ->where('block_group', 'links')
            ->where('settings->category', 'booking')
            ->whereNull('deleted_at')
            ->exists();

        if ($hasLinkBlock) {
            return [true, null];
        }

        // Legacy path: manual booking_url set directly on the booking section block.
        // Kept for backwards compatibility with accounts created before the link
        // block flow was introduced. Uses Laravel's portable JSON arrow syntax so
        // the query works on both Postgres (`->>`) and SQLite (`json_extract`).
        $legacyQuery = Block::query()
            ->where('professional_id', $professionalId)
            ->where('block_group', 'sections')
            ->where('block_type', 'booking')
            ->whereNotNull('settings->booking_url')
            ->where('settings->booking_url', '!=', '');

        if (DB::connection()->getDriverName() === 'pgsql') {
            $legacyQuery->whereRaw("BTRIM(settings->>'booking_url') <> ''");
        }

        if ($legacyQuery->exists()) {
            return [true, null];
        }

        return [false, 'Booking section requires a booking link or booking integration.'];
    }
}
