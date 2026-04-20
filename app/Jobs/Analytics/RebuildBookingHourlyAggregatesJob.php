<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\BookingAnalyticsAggregateService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Rebuilds booking metrics (counts, revenue) for a professional's hour. Booking analytics, not commerce. Queue: analytics.
class RebuildBookingHourlyAggregatesJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(
        public string $professionalId,
        public string $hourStart
    ) {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return "booking-hourly:{$this->professionalId}:{$this->hourStart}";
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
