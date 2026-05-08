<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Phase 3: Receives Shopify orders/paid webhooks. Validates HMAC, deduplicates on
// X-Shopify-Webhook-Id (cheap Redis upfront check), then passes X-Shopify-Event-Id
// into the job for durable DB-level idempotency.
class ShopifyOrderWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $eventId = (string) $request->header('X-Shopify-Event-Id', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        // Cheap Redis upfront dedup on webhook-id before recomputing HMAC.
        $dedupeKey = $webhookId !== '' ? "shopify:webhook:order:{$webhookId}" : null;
        if ($dedupeKey && Cache::has($dedupeKey)) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify order webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        // Claim the webhook-id to prevent concurrent double-dispatch.
        if ($dedupeKey && ! Cache::add($dedupeKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            Log::warning('Shopify order webhook: unknown shop domain', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            Log::warning('Shopify order webhook: invalid JSON payload', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        ProcessShopifyOrderWebhookJob::dispatch(
            (string) $integration->professional_id,
            $payload,
            $eventId,
        );

        return $this->success(['received' => true]);
    }
}
