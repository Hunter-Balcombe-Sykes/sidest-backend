<?php

namespace App\Jobs\Analytics;

use App\Services\Analytics\CommerceAnalyticsAggregateService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Rebuilds all commerce daily aggregates for one brand + affiliate + day from commission_ledger_entries.
class RebuildCommerceDailyAggregatesJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function backoff(): array
    {
        return [5, 30];
    }

    public function __construct(
        public string $brandProfessionalId,
        public string $affiliateProfessionalId,
        public string $day
    ) {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return "commerce-daily:{$this->brandProfessionalId}:{$this->affiliateProfessionalId}:{$this->day}";
    }

    public function handle(CommerceAnalyticsAggregateService $aggregates): void
    {
        $brandId = trim($this->brandProfessionalId);
        $affiliateId = trim($this->affiliateProfessionalId);
        $day = trim($this->day);

        if ($brandId === '' || $affiliateId === '' || $day === '') {
            return;
        }

        $aggregates->rebuildForOrder($brandId, $affiliateId, $day);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Commerce daily aggregate rebuild failed', [
            'brand_professional_id' => $this->brandProfessionalId,
            'affiliate_professional_id' => $this->affiliateProfessionalId,
            'day' => $this->day,
            'message' => $e->getMessage(),
        ]);
    }
}
