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
use Illuminate\Support\Facades\Log;

// V2: Delta/full catalog sync from Fresha to Partna. Booking integration only. Queue: integrations.
class SyncFreshaCatalogDeltaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60];

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

    public function failed(\Throwable $e): void
    {
        Log::warning('Fresha catalog sync job failed', [
            'business_id' => $this->businessId,
            'begin_time' => $this->beginTime,
            'full_sync' => $this->fullSync,
            'message' => $e->getMessage(),
        ]);
    }
}
