<?php

namespace App\Observers\Professional;

use App\Models\Core\Professional\Professional;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates professional cache on profile update/delete/restore.
class ProfessionalObserver
{
    public bool $afterCommit = true;
    public function __construct(
        private ProfessionalCacheService $professionalCache,
    ) {}

    public function updated(Professional $professional): void
    {
        try {
            $this->professionalCache->invalidateProfessional($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on update', [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function deleted(Professional $professional): void
    {
        try {
            $this->professionalCache->invalidateProfessional($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on delete', [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function restored(Professional $professional): void
    {
        try {
            $this->professionalCache->invalidateProfessional($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on restore', [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
