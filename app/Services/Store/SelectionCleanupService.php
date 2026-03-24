<?php

namespace App\Services\Store;

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Site\Site;
use App\Models\Retail\ProfessionalSelection;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

class SelectionCleanupService
{
    public function __construct(
        private readonly SiteCacheService $siteCache
    ) {}

    /**
     * @param  array<int, string>  $brandProductIds
     */
    public function removeSelectionsForBrandProducts(array $brandProductIds, string $title, string $body): int
    {
        $brandProductIds = $this->normalizeIds($brandProductIds);
        if ($brandProductIds === []) {
            return 0;
        }

        $selections = ProfessionalSelection::query()
            ->whereIn('brand_product_id', $brandProductIds)
            ->get(['id', 'professional_id']);

        if ($selections->isEmpty()) {
            return 0;
        }

        $selectionIds = $selections
            ->pluck('id')
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->values()
            ->all();

        $countsByProfessional = $selections
            ->groupBy('professional_id')
            ->map(fn ($rows): int => $rows->count())
            ->all();

        ProfessionalSelection::query()
            ->whereIn('id', $selectionIds)
            ->delete();

        $this->notifyAndInvalidate(array_keys($countsByProfessional), $countsByProfessional, $title, $body);

        return count($selectionIds);
    }

    /**
     * @param  array<int, string>  $brandProductIds
     */
    public function removeSelectionsForAffiliateBrandProducts(
        string $affiliateProfessionalId,
        array $brandProductIds,
        string $title,
        string $body
    ): int {
        $affiliateProfessionalId = trim($affiliateProfessionalId);
        $brandProductIds = $this->normalizeIds($brandProductIds);

        if ($affiliateProfessionalId === '' || $brandProductIds === []) {
            return 0;
        }

        $selectionIds = ProfessionalSelection::query()
            ->where('professional_id', $affiliateProfessionalId)
            ->whereIn('brand_product_id', $brandProductIds)
            ->pluck('id')
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->values()
            ->all();

        if ($selectionIds === []) {
            return 0;
        }

        ProfessionalSelection::query()
            ->whereIn('id', $selectionIds)
            ->delete();

        $this->notifyAndInvalidate(
            [$affiliateProfessionalId],
            [$affiliateProfessionalId => count($selectionIds)],
            $title,
            $body
        );

        return count($selectionIds);
    }

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

        $selectionIds = ProfessionalSelection::query()
            ->where('professional_id', $affiliateProfessionalId)
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('id')
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->values()
            ->all();

        if ($selectionIds === []) {
            return 0;
        }

        ProfessionalSelection::query()
            ->whereIn('id', $selectionIds)
            ->delete();

        $this->notifyAndInvalidate(
            [$affiliateProfessionalId],
            [$affiliateProfessionalId => count($selectionIds)],
            $title,
            $body
        );

        return count($selectionIds);
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
