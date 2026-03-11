<?php

namespace App\Observers\Professional;

use App\Models\Core\Professional\Professional;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Legal\ProfessionalLegalContentService;
use Illuminate\Support\Facades\Log;

class ProfessionalObserver
{
    public bool $afterCommit = true;
    public function __construct(
        private ProfessionalCacheService $professionalCache,
        private ProfessionalLegalContentService $legalContentService
    ) {}

    public function updated(Professional $professional): void
    {
        try {
            $this->legalContentService->refreshGenerated($professional, $professional->site);
        } catch (\Throwable $e) {
            Log::warning('Legal template regeneration failed on professional update', [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]);
        }

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
