<?php

namespace App\Jobs\Square;

use App\Models\Core\Professional\Service;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushServiceToSquareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $queue = 'integrations';

    public function __construct(
        public string $serviceId,
        public string $action = 'upsert'
    ) {}

    public function handle(SquareServiceSyncService $syncService): void
    {
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
