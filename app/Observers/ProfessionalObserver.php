<?php

namespace App\Observers;

use App\Models\Core\Professional\Professional;
use App\Services\Cache\ProfessionalCacheService;

class ProfessionalObserver
{
    public bool $afterCommit = true;
    public function __construct(
        private ProfessionalCacheService $professionalCache
    ) {}

    public function updated(Professional $professional): void
    {
        $this->professionalCache->invalidateProfessional($professional);
    }

    public function deleted(Professional $professional): void
    {
        $this->professionalCache->invalidateProfessional($professional);
    }

    public function restored(Professional $professional): void
    {
        $this->professionalCache->invalidateProfessional($professional);
    }
}
