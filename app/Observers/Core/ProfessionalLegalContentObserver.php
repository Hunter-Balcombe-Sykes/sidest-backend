<?php

namespace App\Observers\Core;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalLegalContent;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates site cache when legal content (privacy policy, terms) changes.
class ProfessionalLegalContentObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache
    ) {}

    public function saved(ProfessionalLegalContent $legalContent): void
    {
        $this->invalidateSiteCache($legalContent);
    }

    public function deleted(ProfessionalLegalContent $legalContent): void
    {
        $this->invalidateSiteCache($legalContent);
    }

    private function invalidateSiteCache(ProfessionalLegalContent $legalContent): void
    {
        try {
            /** @var Professional|null $professional */
            $professional = Professional::query()
                ->with('site')
                ->find($legalContent->professional_id);

            if ($professional?->site) {
                $this->siteCache->invalidateSite($professional->site);
            }
        } catch (\Throwable $e) {
            Log::warning('Site cache invalidation failed on legal content change', [
                'professional_id' => $legalContent->professional_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
