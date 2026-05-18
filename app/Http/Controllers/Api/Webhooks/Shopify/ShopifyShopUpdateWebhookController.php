<?php

namespace App\Http\Controllers\Api\Webhooks\Shopify;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesShopifyWebhook;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Log;

// Receives Shopify shop/update webhooks.
//
// Used to dispatch ProcessShopifyShopUpdateJob (re-sync brand profile fields
// + brand design) on every shop settings change — that has been intentionally
// removed. Brand profile + design are now only pulled from Shopify on first
// connect / reinstall (BrandSignupService::handleReinstall) or when the
// brand explicitly clicks "Resync from Shopify" (ShopifyResyncController /
// BrandDesignController::resync). Auto-syncing on every shop/update was
// overwriting user edits in Sidest in situations the brand didn't expect
// (e.g. updating the shop email in Shopify would flip our display_name).
//
// The endpoint still validates HMAC + dedups (so Shopify doesn't suspend
// the subscription) and returns 200 — it's just a no-op now. RegisterShopifyWebhooksJob
// no longer subscribes new brands to this topic; existing subscriptions stay
// harmlessly dormant.
class ShopifyShopUpdateWebhookController extends ApiController
{
    use HandlesShopifyWebhook;

    protected function topic(): string
    {
        return 'shop/update';
    }

    protected function dedupCachePrefix(): string
    {
        return 'shopify:webhook:shop-update';
    }

    protected function dispatchWebhookJob(
        ProfessionalIntegration $integration,
        array $payload,
        string $eventId,
    ): void {
        Log::info('Shopify shop/update webhook ignored — auto-resync disabled.', [
            'integration_id' => (string) $integration->id,
            'professional_id' => (string) $integration->professional_id,
            'event_id' => $eventId,
            'shop_name' => $payload['name'] ?? null,
        ]);
    }
}
