<?php

namespace App\Http\Controllers\Api\Webhooks\Shopify;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Receives Shopify themes/publish webhooks.
//
// Used to auto-dispatch SyncShopifyBrandDesignJob on every theme publish —
// that has been intentionally removed. Brand design is now only pulled from
// Shopify on first connect / reinstall (BrandSignupService::handleReinstall)
// or when the brand explicitly clicks "Resync from Shopify"
// (ShopifyResyncController / BrandDesignController::resync). Auto-syncing on
// every Shopify theme publish was overwriting user edits in Sidest in
// situations the brand didn't expect.
//
// The endpoint still validates HMAC + dedups (so Shopify doesn't suspend the
// subscription) and returns 200 — it's just a no-op now. RegisterShopifyWebhooksJob
// no longer subscribes new brands to this topic; existing subscriptions stay
// harmlessly dormant.
class ShopifyThemePublishedWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify themes/publish webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        if ($webhookId !== '') {
            $dedupeKey = "shopify:webhook:themes-publish:{$webhookId}";
            if (! Cache::add($dedupeKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if ($integration) {
            Log::info('Shopify themes/publish webhook ignored — auto-resync disabled.', [
                'integration_id' => (string) $integration->id,
                'shop_domain' => $shopDomain,
            ]);
        }

        return $this->success(['received' => true, 'ignored' => true]);
    }
}
