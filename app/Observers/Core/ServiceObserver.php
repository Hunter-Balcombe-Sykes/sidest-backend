<?php

namespace App\Observers\Core;

use App\Jobs\Square\PushServiceToSquareJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\Log;

class ServiceObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly ProfessionalCacheService $professionalCache
    ) {}

    private function bust(Service $service): ?Professional
    {
        $pro = Professional::query()->find($service->professional_id);
        if ($pro) {
            $this->professionalCache->invalidateProfessional($pro);
        }

        return $pro;
    }

    public function saved(Service $service): void
    {
        $pro = $this->bust($service);

        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, 'upsert');
        }
    }

    public function deleted(Service $service): void
    {
        $pro = $this->bust($service);

        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, 'delete');
        }
    }

    public function restored(Service $service): void
    {
        $pro = $this->bust($service);

        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, 'upsert');
        }
    }

    private function dispatchSquareSync(string $serviceId, string $action): void
    {
        try {
            PushServiceToSquareJob::dispatch($serviceId, $action);
        } catch (\Throwable $e) {
            // Never fail core service CRUD because sync dispatch failed.
            Log::warning('PushServiceToSquareJob dispatch failed', [
                'service_id' => $serviceId,
                'action' => $action,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldDispatchSquareSync(?Professional $professional): bool
    {
        if (! $professional) {
            return false;
        }

        if (empty($professional->square_access_token) || empty($professional->square_merchant_id)) {
            return false;
        }

        return (bool) data_get($professional->site?->settings, 'services_auto_sync_enabled', false);
    }
}
