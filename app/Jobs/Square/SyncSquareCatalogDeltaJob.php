<?php

namespace App\Jobs\Square;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Delta/full catalog sync from Square to Partna. Booking integration only. Queue: integrations.
class SyncSquareCatalogDeltaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60];

    public function __construct(
        public string $merchantId,
        public ?string $beginTime = null,
        public bool $fullSync = false
    ) {
        $this->onQueue('integrations');
    }

    public function handle(SquareServiceSyncService $syncService): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
            ->where('external_account_id', $this->merchantId)
            ->first();

        if (! $integration) {
            return;
        }

        $professional = Professional::query()->find($integration->professional_id);

        if (! $professional) {
            return;
        }

        $syncService->syncFromSquare(
            $professional,
            fullSync: $this->fullSync,
            beginTimeOverride: $this->beginTime
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Square catalog sync job failed', [
            'merchant_id' => $this->merchantId,
            'begin_time' => $this->beginTime,
            'full_sync' => $this->fullSync,
            'message' => $e->getMessage(),
        ]);
    }
}
