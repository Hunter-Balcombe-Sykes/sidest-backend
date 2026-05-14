<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Services\Shopify\ShopProfileAutoFillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Processes shop/update webhooks — re-syncs brand profile fields (honouring any
// shopify_sync_locked_fields the brand has set) and triggers a throttled brand-design
// refresh (logos, colours, enums, slogan).
class ProcessShopifyShopUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 30;

    public function __construct(
        public string $professionalId,
        public array $payload,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        $professional = Professional::find($this->professionalId);

        if (! $professional) {
            return;
        }

        $site = Site::where('professional_id', $this->professionalId)->first();

        if (! $site) {
            return;
        }

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $this->professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            Log::warning('Shopify shop/update: no integration record found.', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }

        // Re-sync profile fields, honouring shopify_sync_locked_fields for brands
        // that have customized specific fields locally.
        app(ShopProfileAutoFillService::class)->resyncFromShopData(
            $integration,
            $this->payload,
        );

        // Re-sync the full brand-design shape (logos, colours, enums, slogan).
        // Throttled to once per hour per integration so a chatty shop/update
        // webhook stream can't pummel Shopify with brand-design fetches.
        $cacheKey = "shopify:brand_design_sync:{$integration->id}";
        if (Cache::add($cacheKey, true, now()->addHour())) {
            SyncShopifyBrandDesignJob::dispatch((string) $integration->id);
        }

        Log::info('Shopify shop/update processed.', [
            'professional_id' => $this->professionalId,
            'shop_name' => $this->payload['name'] ?? null,
        ]);
    }
}
