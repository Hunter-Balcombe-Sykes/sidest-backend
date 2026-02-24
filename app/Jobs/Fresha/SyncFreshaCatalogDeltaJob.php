<?php

namespace App\Jobs\Fresha;

use App\Models\Core\Professional\Professional;
use App\Services\Fresha\FreshaServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        $professional = Professional::query()
            ->where('fresha_business_id', $this->businessId)
            ->first();

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
