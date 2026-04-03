<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\SiteAnalyticsAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Rebuilds site visit/click metrics for a professional's hour via SiteAnalyticsAggregateService. Queue: analytics.
class RebuildSiteHourlyAggregatesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        return "site-hourly:{$this->professionalId}:{$this->hourStart}";
    }

    public function handle(SiteAnalyticsAggregateService $aggregates): void
    {
        $professionalId = trim($this->professionalId);
        if ($professionalId === '') {
            return;
        }

        $aggregates->rebuildProfessionalHour($professionalId, $this->hourStart);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Site hourly aggregate rebuild failed', [
            'professional_id' => $this->professionalId,
            'hour_start' => $this->hourStart,
            'message' => $e->getMessage(),
        ]);
    }
}

