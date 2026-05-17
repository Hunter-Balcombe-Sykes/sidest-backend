<?php

namespace App\Http\Controllers\Api\Webhooks\Shopify;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesShopifyWebhook;
use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;

// Phase 3: Receives Shopify orders/updated webhooks. Validates HMAC, deduplicates on
// X-Shopify-Webhook-Id, then passes topic + X-Shopify-Event-Id into the job.
class ShopifyOrdersUpdatedWebhookController extends ApiController
{
    use HandlesShopifyWebhook;

    protected function topic(): string
    {
        return 'orders/updated';
    }

    protected function dedupCachePrefix(): string
    {
        return 'shopify:webhook:order-updated';
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
