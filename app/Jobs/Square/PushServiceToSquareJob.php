<?php

namespace App\Jobs\Square;

use App\Models\Core\Professional\Service;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Pushes service mutations (upsert/delete) to Square. Booking integration only. Queue: integrations.
class PushServiceToSquareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60];

    public function __construct(
        public string $serviceId,
        public string $action = 'upsert'
    ) {
        $this->onQueue('integrations');
    }

    public function handle(SquareServiceSyncService $syncService): void
    {
        $service = Service::query()
            ->withTrashed()
            ->where('id', $this->serviceId)
            ->first();

        if (! $service || $service->trashed()) {
            return;
        }

        $syncService->pushServiceToSquare($service, $this->action);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Square push service job failed', [
            'service_id' => $this->serviceId,
            'action' => $this->action,
            'message' => $e->getMessage(),
        ]);
    }
}
