<?php

namespace App\Jobs\Store;

use App\Models\Core\Professional\Professional;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Seeds an affiliate's product selections from the brand's default Shopify collection.
// Dispatched when an affiliate connects to a brand for the first time.
class SeedAffiliateDefaultSelectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(
        public readonly string $affiliateProfessionalId,
        public readonly string $brandProfessionalId,
    ) {
        $this->onQueue('integrations');
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('SeedAffiliateDefaultSelectionsJob exhausted all retries', [
            'affiliate_professional_id' => $this->affiliateProfessionalId,
            'brand_professional_id' => $this->brandProfessionalId,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(AffiliateProductCatalogService $catalogService): void
    {
        $affiliate = Professional::find($this->affiliateProfessionalId);

        if (! $affiliate) {
            Log::warning('SeedAffiliateDefaultSelectionsJob: affiliate not found.', [
                'affiliate_professional_id' => $this->affiliateProfessionalId,
            ]);

            return;
        }

        try {
            $catalogService->seedDefaultSelections($affiliate, $this->brandProfessionalId, clearExisting: false);
        } catch (\Throwable $e) {
            Log::error('SeedAffiliateDefaultSelectionsJob: failed to seed selections.', [
                'affiliate_professional_id' => $this->affiliateProfessionalId,
                'brand_professional_id' => $this->brandProfessionalId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
