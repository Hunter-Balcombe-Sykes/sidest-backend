<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\BookingAnalyticsAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RebuildBookingHourlyAggregatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public string $professionalId,
        public string $hourStart
    ) {
        $this->onQueue('analytics');
    }

    public function handle(BookingAnalyticsAggregateService $aggregates): void
    {
        $professionalId = trim($this->professionalId);
        if ($professionalId === '') {
            return;
        }

        $aggregates->rebuildProfessionalHour($professionalId, $this->hourStart);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Booking hourly aggregate rebuild failed', [
            'professional_id' => $this->professionalId,
            'hour_start' => $this->hourStart,
            'message' => $e->getMessage(),
        ]);
    }
}

