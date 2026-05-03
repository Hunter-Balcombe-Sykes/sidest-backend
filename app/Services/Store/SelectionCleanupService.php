<?php

namespace App\Services\Store;

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// V2: Updated. Cleans up affiliate product selections when brand-affiliate relationship ends. Now works with affiliate_product_selections table (Shopify GIDs).
class SelectionCleanupService
{
    public function __construct(
        private readonly SiteCacheService $siteCache
    ) {}

    public function removeSelectionsForAffiliateBrand(
        string $affiliateProfessionalId,
        string $brandProfessionalId,
        string $title,
        string $body
    ): int {
        $affiliateProfessionalId = trim($affiliateProfessionalId);
        $brandProfessionalId = trim($brandProfessionalId);

        if ($affiliateProfessionalId === '' || $brandProfessionalId === '') {
            return 0;
        }

        $deleted = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('brand_professional_id', $brandProfessionalId)
            ->delete();

        if ($deleted > 0) {
            $this->notifyAndInvalidate(
                [$affiliateProfessionalId],
                [$affiliateProfessionalId => $deleted],
                $title,
                $body
            );
        }

        return $deleted;
    }

    /**
     * @param  array<int, string>  $professionalIds
     * @param  array<string, int>  $countsByProfessional
     */
    private function notifyAndInvalidate(array $professionalIds, array $countsByProfessional, string $title, string $body): void
    {
        $professionalIds = $this->normalizeIds($professionalIds);

        foreach ($professionalIds as $professionalId) {
            $count = max(0, (int) ($countsByProfessional[$professionalId] ?? 0));
            if ($count <= 0) {
                continue;
            }

            Notification::query()->create([
                'professional_id' => $professionalId,
                'type' => 'Info',
                'title' => $title,
                'body' => str_replace('{count}', (string) $count, $body),
                'severity' => Notification::severityForFrontendType('Info'),
                'starts_at' => now(),
                'ends_at' => null,
            ]);
        }

        $sites = Site::query()
            ->whereIn('professional_id', $professionalIds)
            ->get();

        foreach ($sites as $site) {
            try {
                $this->siteCache->invalidateSite($site);
            } catch (\Throwable $e) {
                Log::warning('Failed to invalidate site cache after selection cleanup.', [
                    'site_id' => (string) ($site->id ?? ''),
                    'professional_id' => (string) ($site->professional_id ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids
        ), static fn (string $id): bool => $id !== '')));
    }
}
