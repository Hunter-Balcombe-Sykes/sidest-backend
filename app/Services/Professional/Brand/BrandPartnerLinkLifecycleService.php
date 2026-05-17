<?php

namespace App\Services\Professional\Brand;

use App\Jobs\Stripe\VoidPendingCommissionsForLinkJob;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\DTO\DisconnectRequest;
use App\Services\Professional\DTO\DisconnectResult;
use App\Services\Professional\Enums\CommissionHandling;
use App\Services\Professional\Enums\DisconnectActor;
use App\Services\Store\SelectionCleanupService;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\DB;
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

    public function disconnect(DisconnectRequest $req): DisconnectResult
    {
        return DB::transaction(function () use ($req): DisconnectResult {
            // 1. Snapshot
            $link = $this->linkService->getLinkForPair($req->affiliate->id, $req->brand->id);
            $pending = $this->commissionVoid->pendingSummaryForAffiliateBrand(
                $req->affiliate->id,
                $req->brand->id,
            );

            // Stale-settings recovery path: link already gone but site settings still reference the brand.
            if (! $link) {
                $site = Site::query()->where('professional_id', $req->affiliate->id)->first();
                if ($site && $this->sync->settingsStillReferenceBrand($site, $req->brand->id)) {
                    $this->sync->sync($site, $req->affiliate->id);
                    DB::afterCommit(fn () => $this->sync->invalidateAffiliateCaches($site));

                    return new DisconnectResult(
                        disconnected: true,
                        voidedCommissionCount: 0,
                        voidedCommissionCents: 0,
                        selectionsRemoved: 0,
                        staleSettingsCleaned: true,
                    );
                }

                return new DisconnectResult(
                    disconnected: false,
                    voidedCommissionCount: 0,
                    voidedCommissionCents: 0,
                    selectionsRemoved: 0,
                );
            }

            // 2. Commission handling
            $voidedAsync = false;
            $voidedCount = 0;
            $voidedCents = 0;

            if ($req->actor === DisconnectActor::Staff && $req->commissions === CommissionHandling::Void) {
                $voidReason = 'link_removed_by_staff: '.($req->reason ?? '');
                $voidResult = $this->commissionVoid->voidPendingForAffiliateBrand(
                    $req->affiliate->id,
                    $req->brand->id,
                    $voidReason,
                );
                if ($voidResult['overflow']) {
                    $voidedAsync = true;
                } else {
                    $voidedCount = $voidResult['count'];
                    $voidedCents = $voidResult['total_cents'];
                }
            }

            // 3. Delete link + renormalize slots
            $this->linkService->disconnectBrandFromAffiliate($req->affiliate->id, $req->brand->id);

            // 4. Scoped selection cleanup
            $selectionsRemoved = $this->selectionCleanup->removeSelectionsForAffiliateBrand(
                $req->affiliate->id,
                $req->brand->id,
                'Brand connection removed',
                '{count} selected product(s) were removed because this brand connection ended.',
            );

            // 5. Sync site settings (in transaction so atomic with link state)
            $site = Site::query()->where('professional_id', $req->affiliate->id)->first();
            if ($site) {
                $this->sync->sync($site, $req->affiliate->id);
            }

            // 6. Audit
            $actorProfessionalId = match ($req->actor) {
                DisconnectActor::Staff => null,
                DisconnectActor::Brand => $req->brand->id,
                DisconnectActor::Affiliate => $req->affiliate->id,
            };
            $this->auditor->recordRemoval(
                brandProfessionalId: $req->brand->id,
                affiliateProfessionalId: $req->affiliate->id,
                actor: $req->actor,
                actorProfessionalId: $actorProfessionalId,
                staffUserId: $req->staffUserId,
                slotAtEvent: (int) $link->slot,
                pendingCount: $pending['count'],
                pendingCents: $pending['total_cents'],
                voidedCount: $voidedCount,
                voidedCents: $voidedCents,
                reason: $req->reason,
            );

            // 7 & 8. After commit: async job (if overflow), notifications, cache invalidation
            if ($voidedAsync) {
                DB::afterCommit(function () use ($req): void {
                    VoidPendingCommissionsForLinkJob::dispatch(
                        affiliateProfessionalId: $req->affiliate->id,
                        brandProfessionalId: $req->brand->id,
                        reason: 'link_removed_by_staff: '.($req->reason ?? ''),
                    );
                });
                // Skip inline notifications — the async job sends them on completion.
            } else {
                $this->dispatchNotifications($req, $voidedCents);
            }

            if ($site) {
                DB::afterCommit(fn () => $this->sync->invalidateAffiliateCaches($site));
            }

            return new DisconnectResult(
                disconnected: true,
                voidedCommissionCount: $voidedCount,
                voidedCommissionCents: $voidedCents,
                selectionsRemoved: $selectionsRemoved,
                pendingCommissionCount: $pending['count'],
                pendingCommissionCents: $pending['total_cents'],
                voidedAsync: $voidedAsync,
            );
        });
    }

    private function dispatchNotifications(DisconnectRequest $req, int $voidedCents): void
    {
        DB::afterCommit(function () use ($req, $voidedCents): void {
            switch ($req->actor) {
                case DisconnectActor::Staff:
                    $this->notifier->notifyAffiliateOfRemoval($req->affiliate, $req->brand, $voidedCents);
                    $this->notifier->notifyBrandOfRemoval($req->brand, $req->affiliate);
                    break;
                case DisconnectActor::Brand:
                    $this->notifier->notifyAffiliateOfRemoval($req->affiliate, $req->brand, $voidedCents);
                    break;
                case DisconnectActor::Affiliate:
                    $this->notifier->notifyBrandOfRemoval($req->brand, $req->affiliate);
                    break;
            }
        });
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
