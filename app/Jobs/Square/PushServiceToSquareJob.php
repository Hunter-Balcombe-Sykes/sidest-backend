<?php

namespace App\Jobs\Square;

use App\Models\Core\Professional\Service;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// V2: Pushes service mutations (upsert/delete) to Square. Booking integration only. Queue: integrations.
class PushServiceToSquareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $serviceId,
        public string $action = 'upsert'
    ) {
        $this->onQueue('integrations');
    }

    public function handle(SquareServiceSyncService $syncService): void
    {
        if (! (bool) config('sidest.features.square_sync', false)) {
            return;
        }

        $service = Service::query()
            ->withTrashed()
            ->where('id', $this->serviceId)
            ->first();

        if (! $service) {
            return;
        }

        $syncService->pushServiceToSquare($service, $this->action);
    }
}
