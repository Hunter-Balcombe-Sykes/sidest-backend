<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesShopifyWebhook;
use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;

// Phase 3: Receives Shopify refunds/create webhooks. Updates refund_cents on the parent order;
// the trg_rollup_clawback trigger handles the brand_affiliate_rollup delta automatically.
class ShopifyRefundsCreateWebhookController extends ApiController
{
    use HandlesShopifyWebhook;

    protected function topic(): string
    {
        return 'refunds/create';
    }

    protected function dedupCachePrefix(): string
    {
        return 'shopify:webhook:refund-create';
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
