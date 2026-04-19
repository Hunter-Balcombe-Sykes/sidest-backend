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

// V2: Receives Shopify orders/paid webhooks. Validates HMAC signature, deduplicates, dispatches processing job.
class ShopifyOrderWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        // HMAC signature validation
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify order webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        // Event deduplication
        if ($webhookId !== '') {
            $dedupeKey = "shopify:webhook:order:{$webhookId}";
            if (! Cache::add($dedupeKey, true, now()->addHours(24))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }

        // Identify brand by shop domain
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

        // Dispatch processing job
        try {
            ProcessShopifyOrderWebhookJob::dispatch(
                (string) $integration->professional_id,
                $payload
            );
        } catch (\Throwable $e) {
            Log::error('Shopify order webhook: failed to dispatch processing job', [
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success(['received' => true]);
    }
}
