<?php

namespace App\Observers\Commerce;

use App\Models\Commerce\AffiliateProductSelection;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// Master Pattern 15: busts the Hydrogen affiliate-products cache when an
// affiliate adds, reorders, or removes a product selection. Without this, the
// 60s TTL on HydrogenAffiliateProductsController would gate the visible-to-user
// lag when an affiliate curates their storefront.
class AffiliateProductSelectionObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache,
    ) {}

    public function saved(AffiliateProductSelection $selection): void
    {
        $this->bust($selection);
    }

    public function deleted(AffiliateProductSelection $selection): void
    {
        $this->bust($selection);
    }

    private function bust(AffiliateProductSelection $selection): void
    {
        $affiliateId = trim((string) ($selection->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        try {
            $this->siteCache->forgetHydrogenAffiliateProducts($affiliateId);
        } catch (\Throwable $e) {
            Log::warning('AffiliateProductSelectionObserver: Hydrogen cache bust failed', $this->logContext(__METHOD__, [
                'selection_id' => $selection->id,
                'affiliate_professional_id' => $affiliateId,
                'message' => $e->getMessage(),
            ]));
        }
    }
}
