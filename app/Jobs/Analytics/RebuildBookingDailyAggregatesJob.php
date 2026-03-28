<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\BookingAnalyticsAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RebuildBookingDailyAggregatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public string $professionalId,
        public string $day
    ) {
        $this->onQueue('analytics');
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

