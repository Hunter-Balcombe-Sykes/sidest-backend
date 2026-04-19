<?php

namespace App\Observers\Core;

use App\Jobs\Notifications\FanOutBrandStatusNotificationJob;
use App\Models\Core\Professional\BrandProfile;
use Illuminate\Support\Facades\Log;

// V2: Dispatches affiliate notification fan-out when brand_status changes (active/deactivated).
class BrandProfileObserver
{
    public bool $afterCommit = true;

    public function updated(BrandProfile $brandProfile): void
    {
        try {
            if (! $brandProfile->isDirty('brand_status')) {
                return;
            }

            $brandProfessionalId = trim((string) ($brandProfile->professional_id ?? ''));
            if ($brandProfessionalId === '') {
                return;
            }

            $newStatus = $brandProfile->brand_status;
            if (! in_array($newStatus, ['active', 'deactivated'], true)) {
                return;
            }

            FanOutBrandStatusNotificationJob::dispatch($brandProfessionalId, $newStatus);
        } catch (\Throwable $e) {
            Log::warning('BrandProfile updated notification dispatch failed', [
                'brand_profile_id' => $brandProfile->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
