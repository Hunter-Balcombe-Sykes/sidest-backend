<?php

namespace App\Jobs\Square;

use App\Models\Core\Professional\Professional;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSquareCatalogDeltaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $queue = 'integrations';

    public function __construct(
        public string $merchantId,
        public ?string $beginTime = null,
        public bool $fullSync = false
    ) {}

    public function handle(SquareServiceSyncService $syncService): void
    {
        $professional = Professional::query()
            ->where('square_merchant_id', $this->merchantId)
            ->first();

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

