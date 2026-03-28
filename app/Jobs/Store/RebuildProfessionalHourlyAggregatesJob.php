<?php

namespace App\Jobs\Store;

use App\Services\Store\OrderAnalyticsHourlyAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RebuildProfessionalHourlyAggregatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public string $affiliateProfessionalId,
        public string $hourStart
    ) {
        $this->onQueue('analytics');
    }

    public function handle(OrderAnalyticsHourlyAggregateService $aggregates): void
    {
        $affiliateProfessionalId = trim($this->affiliateProfessionalId);
        if ($affiliateProfessionalId === '') {
            return;
        }

        $aggregates->rebuildProfessionalHour($affiliateProfessionalId, $this->hourStart);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Professional hourly aggregate rebuild failed', [
            'affiliate_professional_id' => $this->affiliateProfessionalId,
            'hour_start' => $this->hourStart,
            'message' => $e->getMessage(),
        ]);
    }
}

