<?php

namespace App\Observers\Core;

use App\Jobs\Fresha\PushServiceToFreshaJob;
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
        try {
            $pro = Professional::query()->find($service->professional_id);
            if ($pro) {
                $this->professionalCache->invalidateProfessional($pro);
            }

            return $pro;
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on service change', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
                'message' => $e->getMessage(),
            ]);
        }

        return Professional::query()->find($service->professional_id);
    }

    public function saved(Service $service): void
    {
        $pro = $this->bust($service);

        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, 'upsert');
        }

        if ($this->shouldDispatchFreshaSync($pro)) {
            $this->dispatchFreshaSync($service->id, 'upsert');
        }
    }

    public function deleted(Service $service): void
    {
        $pro = $this->bust($service);

        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, 'delete');
        }

        if ($this->shouldDispatchFreshaSync($pro)) {
            $this->dispatchFreshaSync($service->id, 'delete');
        }
    }

    public function restored(Service $service): void
    {
        $pro = $this->bust($service);

        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, 'upsert');
        }

        if ($this->shouldDispatchFreshaSync($pro)) {
            $this->dispatchFreshaSync($service->id, 'upsert');
        }
    }

    private function dispatchSquareSync(string $serviceId, string $action): void
    {
        try {
            // Run immediately so Square updates work even when no worker cluster is running.
            PushServiceToSquareJob::dispatchSync($serviceId, $action);
        } catch (\Throwable $e) {
            // Never fail core service CRUD because sync dispatch failed.
            Log::warning('PushServiceToSquareJob dispatch failed', [
                'service_id' => $serviceId,
                'action' => $action,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchFreshaSync(string $serviceId, string $action): void
    {
        try {
            // Run immediately so Fresha updates work even when no worker cluster is running.
            PushServiceToFreshaJob::dispatchSync($serviceId, $action);
        } catch (\Throwable $e) {
            // Never fail core service CRUD because sync dispatch failed.
            Log::warning('PushServiceToFreshaJob dispatch failed', [
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

    private function shouldDispatchFreshaSync(?Professional $professional): bool
    {
        if (! $professional) {
            return false;
        }

        if (empty($professional->fresha_access_token) || empty($professional->fresha_business_id)) {
            return false;
        }

        return (bool) data_get($professional->site?->settings, 'services_auto_sync_enabled', false);
    }
}
