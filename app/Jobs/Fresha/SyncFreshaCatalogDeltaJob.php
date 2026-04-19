<?php

namespace App\Jobs\Fresha;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Fresha\FreshaServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// V2: Delta/full catalog sync from Fresha to Side St. Booking integration only. Queue: integrations.
class SyncFreshaCatalogDeltaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $businessId,
        public ?string $beginTime = null,
        public bool $fullSync = false
    ) {
        $this->onQueue('integrations');
    }

    public function handle(FreshaServiceSyncService $syncService): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA)
            ->where('external_account_id', $this->businessId)
            ->first();

        if (! $integration) {
            return;
        }

        $professional = Professional::query()->find($integration->professional_id);

        if (! $professional) {
            return;
        }

        $syncService->syncFromFresha(
            $professional,
            fullSync: $this->fullSync,
            beginTimeOverride: $this->beginTime
        );
    }
}
