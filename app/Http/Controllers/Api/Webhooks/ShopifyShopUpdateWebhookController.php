<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesShopifyWebhook;
use App\Jobs\Shopify\ProcessShopifyShopUpdateJob;
use App\Models\Core\Professional\ProfessionalIntegration;

// V2: Receives Shopify shop/update webhooks. Validates HMAC, deduplicates, dispatches processing job for profile re-sync.
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
        ProcessShopifyShopUpdateJob::dispatch(
            (string) $integration->professional_id,
            $payload,
        );
    }
}
