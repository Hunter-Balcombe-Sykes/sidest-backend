<?php

namespace App\Jobs\Shopify;

use App\Jobs\Store\RebuildBrandDailyAggregatesJob;
use App\Jobs\Store\RebuildProfessionalDailyAggregatesJob;
use App\Services\Store\ShopifyOrderProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessShopifyOrderEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $inboxId
    ) {
        $this->onQueue('integrations');
    }

    public function handle(ShopifyOrderProcessingService $processor): void
    {
        $result = $processor->processInbox($this->inboxId);

        if (($result['status'] ?? null) !== 'processed') {
            return;
        }

        $brandProfessionalId = trim((string) ($result['brand_professional_id'] ?? ''));
        $affiliateProfessionalId = trim((string) ($result['affiliate_professional_id'] ?? ''));
        $orderedDay = trim((string) ($result['ordered_day'] ?? ''));

        if ($brandProfessionalId !== '' && $orderedDay !== '') {
            RebuildBrandDailyAggregatesJob::dispatch($brandProfessionalId, $orderedDay, 1);
        }

        if ($affiliateProfessionalId !== '' && $orderedDay !== '') {
            RebuildProfessionalDailyAggregatesJob::dispatch($affiliateProfessionalId, $orderedDay, 1);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Shopify order event processing job failed', [
            'inbox_id' => $this->inboxId,
            'message' => $e->getMessage(),
        ]);
    }
}
