<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\CommerceAnalyticsAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Rebuilds commerce hourly aggregates for one brand + affiliate + UTC hour from commission_ledger_entries.
// Dispatched immediately after a Shopify order webhook to keep the last-24h dashboard current.
class RebuildCommerceHourlyAggregatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function backoff(): array
    {
        return [5, 30];
    }

    public function __construct(
        public string $brandProfessionalId,
        public string $affiliateProfessionalId,
        public string $hourStart
    ) {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return "commerce-hourly:{$this->brandProfessionalId}:{$this->affiliateProfessionalId}:{$this->hourStart}";
    }

    public function handle(CommerceAnalyticsAggregateService $aggregates): void
    {
        $brandId = trim($this->brandProfessionalId);
        $affiliateId = trim($this->affiliateProfessionalId);

        if ($brandId === '' || $affiliateId === '') {
            return;
        }

        $aggregates->rebuildForHour($brandId, $affiliateId, $this->hourStart);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Commerce hourly aggregate rebuild failed', [
            'brand_professional_id' => $this->brandProfessionalId,
            'affiliate_professional_id' => $this->affiliateProfessionalId,
            'hour_start' => $this->hourStart,
            'message' => $e->getMessage(),
        ]);
    }
}
