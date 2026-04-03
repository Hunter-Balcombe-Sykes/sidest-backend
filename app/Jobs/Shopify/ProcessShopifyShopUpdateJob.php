<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\BrandProfile;
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

// V2: Processes shop/update webhooks — re-syncs brand profile fields and dispatches logo sync.
class ProcessShopifyShopUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

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

        $brandProfile = BrandProfile::where('professional_id', $this->professionalId)->first();
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $this->professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        // Re-sync profile fields from the shop/update payload (which is the shop object)
        app(ShopProfileAutoFillService::class)->fillFromShopData(
            $professional,
            $site,
            $brandProfile,
            $this->payload,
            $integration,
        );

        // Re-sync logo via GraphQL (always overwrites), throttled to once per hour per integration
        if ($integration) {
            $cacheKey = "shopify:logo_sync:{$integration->id}";
            if (Cache::add($cacheKey, true, now()->addHour())) {
                SyncShopifyBrandLogoJob::dispatch((string) $integration->id);
            }
        }

        Log::info('Shopify shop/update processed.', [
            'professional_id' => $this->professionalId,
            'shop_name' => $this->payload['name'] ?? null,
        ]);
    }
}
