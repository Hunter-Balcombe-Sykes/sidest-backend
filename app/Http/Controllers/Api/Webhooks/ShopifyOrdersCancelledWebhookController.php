<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Phase 3: Receives Shopify orders/cancelled webhooks. Sets order status to 'cancelled';
// first-seen orders get a stub row with status='cancelled'.
class ShopifyOrdersCancelledWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $eventId = (string) $request->header('X-Shopify-Event-Id', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        $dedupeKey = $webhookId !== '' ? "shopify:webhook:order-cancelled:{$webhookId}" : null;
        if ($dedupeKey && Cache::has($dedupeKey)) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify orders/cancelled webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        if ($dedupeKey && ! Cache::add($dedupeKey, true, now()->addHours(24))) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            Log::warning('Shopify orders/cancelled webhook: unknown shop domain', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return $this->success(['received' => true]);
        }

        ProcessShopifyOrderUpdatedWebhookJob::dispatch(
            (string) $integration->professional_id,
            $payload,
            'orders/cancelled',
            $eventId,
        );

        return $this->success(['received' => true]);
    }
}
