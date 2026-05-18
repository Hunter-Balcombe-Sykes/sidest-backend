<?php

namespace App\Jobs\Fresha;

use App\Models\Core\Professional\Service;
use App\Services\Fresha\FreshaServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Pushes service mutations (upsert/delete) to Fresha. Booking integration only. Queue: integrations.
class PushServiceToFreshaJob implements ShouldQueue
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

    public function handle(FreshaServiceSyncService $syncService): void
    {
        $service = Service::query()
            ->withTrashed()
            ->where('id', $this->serviceId)
            ->first();

        if (! $service || $service->trashed()) {
            return;
        }

        $syncService->pushServiceToFresha($service, $this->action);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Fresha push service job failed', [
            'service_id' => $this->serviceId,
            'action' => $this->action,
            'message' => $e->getMessage(),
        ]);
    }
}
