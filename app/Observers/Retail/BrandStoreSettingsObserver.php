<?php

namespace App\Observers\Retail;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// V2: Busts the dashboard /api/me cache helper for brand store settings on
// any write. Without this the dashboard would lag store-settings edits by up
// to 30 minutes (the cache helper's TTL).
// Also cascades Cloudflare KV sync when custom domain changes, since the brand's
// partna_url (and all connected affiliates' site_url) derives from the custom domain.
class BrandStoreSettingsObserver
{
    public bool $afterCommit = true;

    public function saved(BrandStoreSettings $settings): void
    {
        $this->bust($settings);
        $this->syncKvIfDomainChanged($settings);
    }

    public function deleted(BrandStoreSettings $settings): void
    {
        $this->bust($settings);
    }

    private function bust(BrandStoreSettings $settings): void
    {
        $professionalId = trim((string) ($settings->professional_id ?? ''));
        if ($professionalId === '') {
            return;
        }

        try {
            $key = CacheKeyGenerator::brandStoreSettings($professionalId);
            Cache::forget($key);
        } catch (\Throwable $e) {
            Log::warning('BrandStoreSettings cache invalidation failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // Custom domain change rewrites the brand's partna_url (via DB trigger), which means
    // all connected affiliates' site_url also changes. Cascade KV sync to keep routing fresh.
    private function syncKvIfDomainChanged(BrandStoreSettings $settings): void
    {
        if (! $settings->wasChanged('custom_domain') && ! $settings->wasChanged('custom_domain_verified_at')) {
            return;
        }

        $professionalId = trim((string) ($settings->professional_id ?? ''));
        if ($professionalId === '') {
            return;
        }

        try {
            SyncSubdomainToKvJob::dispatch($professionalId);
        } catch (\Throwable $e) {
            Log::warning('BrandStoreSettingsObserver: KV sync dispatch failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            BrandPartnerLink::query()
                ->where('brand_professional_id', $professionalId)
                ->pluck('affiliate_professional_id')
                ->each(function (string $affiliateId): void {
                    SyncSubdomainToKvJob::dispatch($affiliateId);
                });
        } catch (\Throwable $e) {
            Log::warning('BrandStoreSettingsObserver: KV affiliate cascade failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
