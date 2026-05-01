<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Receives Shopify themes/publish webhooks. When a brand publishes a new theme,
// this re-syncs their brand design tokens (colours, logos, radius) into the platform.
class ShopifyThemePublishedWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody   = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify themes/publish webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            // Return 200 regardless — Shopify retries on non-2xx, which would flood logs.
            return $this->success(['received' => true]);
        }

        // Deduplicate: Shopify may deliver the same webhook ID more than once.
        if ($webhookId !== '') {
            $dedupeKey = "shopify:webhook:themes-publish:{$webhookId}";
            if (! Cache::add($dedupeKey, true, now()->addHours(24))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            Log::warning('Shopify themes/publish webhook: unknown shop domain', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        SyncShopifyBrandDesignJob::dispatch((string) $integration->id);

        return $this->success(['received' => true]);
    }
}
