<?php

namespace App\Observers;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Services\Cache\ProfessionalCacheService;

class ServiceObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly ProfessionalCacheService $professionalCache
    ) {}

    private function bust(Service $service): void
    {
        $pro = Professional::query()->find($service->professional_id);
        if ($pro) {
            $this->professionalCache->invalidateProfessional($pro);
        }
    }

    public function saved(Service $service): void
    {
        $this->bust($service);
    }

    public function deleted(Service $service): void
    {
        $this->bust($service);
    }

    public function restored(Service $service): void
    {
        $this->bust($service);
    }
}
