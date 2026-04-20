<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\SiteAnalyticsAggregateService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Rebuilds site visit/click daily metrics for a professional. Queue: analytics.
class RebuildSiteDailyAggregatesJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        return "site-daily:{$this->professionalId}:{$this->day}";
    }

    public function handle(SiteAnalyticsAggregateService $aggregates): void
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
        Log::warning('Site daily aggregate rebuild failed', [
            'professional_id' => $this->professionalId,
            'day' => $this->day,
            'message' => $e->getMessage(),
        ]);
    }
}
