<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Store\SelectionCleanupService;
use App\Services\Stripe\CommissionVoidService;
use RuntimeException;
use Throwable;

// Orchestrates the full lifecycle of brand-affiliate links — create and
// disconnect — across the three actor types (staff, brand, affiliate).
//
// Responsible for guard evaluation, transactional side-effect ordering,
// and post-commit dispatch of notifications / cache invalidation / jobs.
//
// The primitive DB operations (insert link, delete link + renormalize
// slots) live in BrandPartnerLinkService. This class composes that
// service with auditing, notifications, selection cleanup, commission
// voiding, and site settings sync.
class BrandPartnerLinkLifecycleService
{
    public function __construct(
        private readonly BrandPartnerLinkService $linkService,
        private readonly SelectionCleanupService $selectionCleanup,
        private readonly CommissionVoidService $commissionVoid,
        private readonly BrandPartnerLinkNotifier $notifier,
        private readonly BrandPartnerLinkAuditor $auditor,
        private readonly BrandPartnerSiteSettingsSync $sync,
    ) {}

    /**
     * Staff-only manual link creation. Throws RuntimeException on guard
     * failures so controllers can translate to 422 responses.
     */
    public function createForStaff(
        Professional $brand,
        Professional $affiliate,
        string $reason,
        string $staffUserId,
    ): BrandPartnerLink {
        $this->assertCreateGuards($brand, $affiliate);

        try {
            $link = $this->linkService->connectBrandToAffiliate($affiliate->id, $brand->id);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        $this->auditor->recordCreation($brand->id, $affiliate->id, $staffUserId, (int) $link->slot, $reason);

        $site = Site::query()->where('professional_id', $affiliate->id)->first();
        if ($site) {
            $this->sync->sync($site, $affiliate->id);
            $this->sync->invalidateAffiliateCaches($site);
        }

        return $link;
    }

    private function assertCreateGuards(Professional $brand, Professional $affiliate): void
    {
        if (mb_strtolower(trim((string) $brand->professional_type)) !== 'brand') {
            throw new RuntimeException('Target brand is not a brand account.');
        }

        if (mb_strtolower(trim((string) $affiliate->professional_type)) === 'brand') {
            throw new RuntimeException('Cannot link two brand accounts.');
        }

        if ($brand->status === 'deactivated' || $affiliate->status === 'deactivated') {
            throw new RuntimeException('Cannot link a deactivated professional.');
        }
    }
}
