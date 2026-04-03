<?php

namespace App\Observers\Core;

use App\Jobs\Fresha\PushServiceToFreshaJob;
use App\Jobs\Square\PushServiceToSquareJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates cache, re-evaluates booking visibility, and dispatches Square/Fresha sync on service changes.
class ServiceObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly ProfessionalCacheService $professionalCache,
        private readonly SectionVisibilityService $visibilityService,
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

        $this->reevaluateBooking($service, $pro);

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

        $this->reevaluateBooking($service, $pro);

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

        $this->reevaluateBooking($service, $pro);

        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, 'upsert');
        }

        if ($this->shouldDispatchFreshaSync($pro)) {
            $this->dispatchFreshaSync($service->id, 'upsert');
        }
    }

    private function reevaluateBooking(Service $service, ?Professional $pro): void
    {
        try {
            $site = $pro?->site;
            if (! $site) {
                return;
            }

            $this->visibilityService->reevaluateEnabled(
                (string) $service->professional_id,
                (string) $site->id,
                'booking'
            );
        } catch (\Throwable $e) {
            Log::warning('Booking section visibility reevaluation failed on service change', [
                'service_id'      => $service->id,
                'professional_id' => $service->professional_id,
                'message'         => $e->getMessage(),
            ]);
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

        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_SQUARE);
        if (! $integration || empty($integration->access_token) || empty($integration->external_account_id)) {
            return false;
        }

        return (bool) data_get($professional->site?->settings, 'services_auto_sync_enabled', false);
    }

    private function shouldDispatchFreshaSync(?Professional $professional): bool
    {
        if (! $professional) {
            return false;
        }

        $integration = $professional->integrationForProvider(ProfessionalIntegration::PROVIDER_FRESHA);
        if (! $integration || empty($integration->access_token) || empty($integration->external_account_id)) {
            return false;
        }

        return (bool) data_get($professional->site?->settings, 'services_auto_sync_enabled', false);
    }
}
