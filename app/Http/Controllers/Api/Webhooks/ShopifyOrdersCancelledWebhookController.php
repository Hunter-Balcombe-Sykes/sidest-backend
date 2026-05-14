<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesShopifyWebhook;
use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;

// Phase 3: Receives Shopify orders/cancelled webhooks. Sets order status to 'cancelled';
// first-seen orders get a stub row with status='cancelled'.
class ShopifyOrdersCancelledWebhookController extends ApiController
{
    use HandlesShopifyWebhook;

    protected function topic(): string
    {
        return 'orders/cancelled';
    }

    protected function dedupCachePrefix(): string
    {
        return 'shopify:webhook:order-cancelled';
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
