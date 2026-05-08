<?php

namespace App\Observers\Core;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\BrandPartnerLink;
use Illuminate\Support\Facades\Log;

// Syncs affiliate subdomain routing in Cloudflare KV when a brand connection is
// created or removed. Affiliates redirect from their own subdomain to brand.partna.au/affiliate.
class BrandPartnerLinkObserver
{
    public bool $afterCommit = true;

    public function created(BrandPartnerLink $link): void
    {
        $this->dispatchSync($link);
    }

    public function deleted(BrandPartnerLink $link): void
    {
        $this->dispatchSync($link);
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
            Log::warning('BrandPartnerLinkObserver: KV sync dispatch failed', [
                'affiliate_professional_id' => $affiliateId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
