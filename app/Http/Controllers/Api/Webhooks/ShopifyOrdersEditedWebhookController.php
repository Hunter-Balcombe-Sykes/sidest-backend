<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesShopifyWebhook;
use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;

// Phase 3: Receives Shopify orders/edited webhooks. Commission is frozen at orders/paid time
// (Decision #3) — this path only snapshots updated order data.
class ShopifyOrdersEditedWebhookController extends ApiController
{
    use HandlesShopifyWebhook;

    protected function topic(): string
    {
        return 'orders/edited';
    }

    protected function dedupCachePrefix(): string
    {
        return 'shopify:webhook:order-edited';
    }

    protected function claimWebhookEvent(string $webhookId): bool
    {
        return $this->claimShopifyWebhookEvent($webhookId, $this->topic());
    }

    protected function dispatchWebhookJob(
        ProfessionalIntegration $integration,
        array $payload,
        string $eventId,
    ): void {
        ProcessShopifyOrderUpdatedWebhookJob::dispatch(
            (string) $integration->professional_id,
            $payload,
            $this->topic(),
            $eventId,
        );
    }
}
