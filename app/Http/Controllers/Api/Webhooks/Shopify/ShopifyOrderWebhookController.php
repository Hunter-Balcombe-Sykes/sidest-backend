<?php

namespace App\Http\Controllers\Api\Webhooks\Shopify;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesShopifyWebhook;
use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;

// Phase 3: Receives Shopify orders/paid webhooks. Validates HMAC, deduplicates on
// X-Shopify-Webhook-Id, then passes X-Shopify-Event-Id into the job for durable
// DB-level idempotency via the order_events UNIQUE constraint.
class ShopifyOrderWebhookController extends ApiController
{
    use HandlesShopifyWebhook;

    protected function topic(): string
    {
        return 'orders/paid';
    }

    protected function dedupCachePrefix(): string
    {
        return 'shopify:webhook:order';
    }

    protected function dispatchWebhookJob(
        ProfessionalIntegration $integration,
        array $payload,
        string $eventId,
    ): void {
        ProcessShopifyOrderWebhookJob::dispatch(
            (string) $integration->professional_id,
            $payload,
            $eventId,
        );
    }
}
