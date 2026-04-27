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
        // Eager-load site once — every downstream caller (reevaluateBooking,
        // shouldDispatchSquareSync, shouldDispatchFreshaSync) reads $pro->site.
        $pro = Professional::query()->with('site')->find($service->professional_id);

        try {
            if ($pro) {
                $this->professionalCache->invalidateProfessional($pro);
            }
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on service change', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
                'message' => $e->getMessage(),
            ]);
        }

        return $pro;
    }

    public function saved(Service $service): void
    {
        $this->runHooks($service, 'upsert');
    }

    public function deleted(Service $service): void
    {
        $this->runHooks($service, 'delete');
    }

    public function restored(Service $service): void
    {
        $this->runHooks($service, 'upsert');
    }

    /**
     * Side-effect runner. Wraps every step in its own try/catch + a
     * top-level catch-all so an observer failure can never bubble up
     * and turn the originating Service::save() into a 500. Logs every
     * failure with the service id for triage.
     */
    private function runHooks(Service $service, string $action): void
    {
        try {
            $pro = $this->bust($service);
            $this->reevaluateBooking($service, $pro);

            if ($this->shouldDispatchSquareSync($pro)) {
                $this->dispatchSquareSync($service->id, $action);
            }

            if ($this->shouldDispatchFreshaSync($pro)) {
                $this->dispatchFreshaSync($service->id, $action);
            }
        } catch (\Throwable $e) {
            // Catch-all so a sync/cache/visibility failure can't trip the
            // request. Each step has its own try/catch; this wraps the
            // glue + any unanticipated error in helper resolution.
            Log::error('ServiceObserver hook failed', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
                'action' => $action,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function reevaluateBooking(Service $service, ?Professional $pro): void
    {
        try {
            $site = $pro?->site;
            if (! $site) {
                return;
            }

            // Both sections gate on "has at least one valid service": booking
            // requires it for the booking link/integration check, services
            // requires it directly. Re-evaluate both so is_enabled tracks
            // reality after add/update/delete/restore.
            foreach (['booking', 'services'] as $blockType) {
                $this->visibilityService->reevaluateEnabled(
                    (string) $service->professional_id,
                    (string) $site->id,
                    $blockType,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Section visibility reevaluation failed on service change', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
                'message' => $e->getMessage(),
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
        if (! (bool) config('sidest.features.square_sync', false)) {
            return false;
        }

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
        if (! (bool) config('sidest.features.fresha_sync', false)) {
            return false;
        }

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
