<?php

namespace App\Jobs\Store;

use App\Services\Store\OrderAnalyticsAggregateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RebuildProfessionalDailyAggregatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $affiliateProfessionalId,
        public string $anchorDay,
        public int $windowDays = 35
    ) {
        $this->onQueue('analytics');
    }

    public function handle(OrderAnalyticsAggregateService $aggregates): void
    {
        $affiliateProfessionalId = trim($this->affiliateProfessionalId);
        if ($affiliateProfessionalId === '') {
            return;
        }

        $anchor = Carbon::parse($this->anchorDay)->startOfDay();
        $windowDays = max(1, min(120, $this->windowDays));

        for ($offset = 0; $offset < $windowDays; $offset++) {
            $day = $anchor->copy()->subDays($offset)->toDateString();
            $aggregates->rebuildProfessionalDay($affiliateProfessionalId, $day);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Professional daily aggregate rebuild failed', [
            'affiliate_professional_id' => $this->affiliateProfessionalId,
            'anchor_day' => $this->anchorDay,
            'window_days' => $this->windowDays,
            'message' => $e->getMessage(),
        ]);
    }
}
