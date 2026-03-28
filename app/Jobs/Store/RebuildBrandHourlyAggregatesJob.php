<?php

namespace App\Jobs\Store;

use App\Services\Store\OrderAnalyticsHourlyAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RebuildBrandHourlyAggregatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public string $brandProfessionalId,
        public string $hourStart
    ) {
        $this->onQueue('analytics');
    }

    public function handle(OrderAnalyticsHourlyAggregateService $aggregates): void
    {
        $brandProfessionalId = trim($this->brandProfessionalId);
        if ($brandProfessionalId === '') {
            return;
        }

        $aggregates->rebuildBrandHour($brandProfessionalId, $this->hourStart);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Brand hourly aggregate rebuild failed', [
            'brand_professional_id' => $this->brandProfessionalId,
            'hour_start' => $this->hourStart,
            'message' => $e->getMessage(),
        ]);
    }
}

