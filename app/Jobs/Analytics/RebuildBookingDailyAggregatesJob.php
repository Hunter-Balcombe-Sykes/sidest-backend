<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\BookingAnalyticsAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Rebuilds booking daily metrics for a professional. Booking analytics, not commerce. Queue: analytics.
class RebuildBookingDailyAggregatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(
        public string $professionalId,
        public string $day
    ) {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return "booking-daily:{$this->professionalId}:{$this->day}";
    }

    public function handle(BookingAnalyticsAggregateService $aggregates): void
    {
        $professionalId = trim($this->professionalId);
        $day = trim($this->day);

        if ($professionalId === '' || $day === '') {
            return;
        }

        $aggregates->rebuildProfessionalDay($professionalId, $day);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Booking daily aggregate rebuild failed', [
            'professional_id' => $this->professionalId,
            'day' => $this->day,
            'message' => $e->getMessage(),
        ]);
    }
}
