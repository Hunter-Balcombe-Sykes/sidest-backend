<?php

namespace App\Jobs\Fresha;

use App\Models\Core\Professional\Service;
use App\Services\Fresha\FreshaServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// V2: Pushes service mutations (upsert/delete) to Fresha. Booking integration only. Queue: integrations.
class PushServiceToFreshaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $serviceId,
        public string $action = 'upsert'
    ) {
        $this->onQueue('integrations');
    }

    public function handle(FreshaServiceSyncService $syncService): void
    {
        if (! (bool) config('sidest.features.fresha_sync', false)) {
            return;
        }

        $service = Service::query()
            ->withTrashed()
            ->where('id', $this->serviceId)
            ->first();

        if (! $service || $service->trashed()) {
            return;
        }

        $syncService->pushServiceToFresha($service, $this->action);
    }
}
