<?php

namespace App\Observers\Core;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Site\Site;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// Syncs affiliate subdomain routing in Cloudflare KV when a brand connection is
// created or removed. Affiliates redirect from their own subdomain to brand.partna.au/affiliate.
//
// Master Pattern 15: also busts the Hydrogen affiliate-page cache — the cache
// entry's existence depends on the link (the controller 404s without it), and
// the affiliate-products cache depends on it indirectly.
class BrandPartnerLinkObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache,
    ) {}

    public function created(BrandPartnerLink $link): void
    {
        $this->dispatchSync($link);
        $this->bustHydrogenCaches($link);
    }

    public function deleted(BrandPartnerLink $link): void
    {
        $this->dispatchSync($link);
        $this->bustHydrogenCaches($link);
    }

    private function dispatchSync(BrandPartnerLink $link): void
    {
        $affiliateId = trim((string) ($link->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        try {
            SyncSubdomainToKvJob::dispatch($affiliateId);
        } catch (\Throwable $e) {
            Log::warning('BrandPartnerLinkObserver: KV sync dispatch failed', $this->logContext(__METHOD__, [
                'affiliate_professional_id' => $affiliateId,
                'brand_professional_id' => $link->brand_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function bustHydrogenCaches(BrandPartnerLink $link): void
    {
        $affiliateId = trim((string) ($link->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        try {
            $siteId = Site::query()
                ->where('professional_id', $affiliateId)
                ->value('id');
            if (is_string($siteId) && $siteId !== '') {
                $this->siteCache->forgetHydrogenAffiliate($siteId);
            }
            $this->siteCache->forgetHydrogenAffiliateProducts($affiliateId);
        } catch (\Throwable $e) {
            Log::warning('BrandPartnerLinkObserver: Hydrogen cache bust failed', $this->logContext(__METHOD__, [
                'affiliate_professional_id' => $affiliateId,
                'brand_professional_id' => $link->brand_professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }
}
