<?php

namespace App\Observers\Retail;

use App\Models\Retail\BrandStoreSettings;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// V2: Busts the dashboard /api/me cache helper for brand store settings on
// any write. Without this the dashboard would lag store-settings edits by up
// to 30 minutes (the cache helper's TTL).
class BrandStoreSettingsObserver
{
    public bool $afterCommit = true;

    public function saved(BrandStoreSettings $settings): void
    {
        $this->bust($settings);
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
}
