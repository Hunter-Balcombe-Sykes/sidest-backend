<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Handles mandatory Shopify GDPR webhooks (customers/data_request, customers/redact, shop/redact). Stub — logs and acknowledges.
class ShopifyGdprWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function customersDataRequest(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, 'customers/data_request');
    }

    public function customersRedact(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, 'customers/redact');
    }

    public function shopRedact(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, 'shop/redact');
    }

    private function handleGdprWebhook(Request $request, string $topic): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning("Shopify GDPR webhook ({$topic}): invalid HMAC signature", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        $payload = json_decode($rawBody, true);

        Log::info("Shopify GDPR webhook received: {$topic}", [
            'shop_domain' => $shopDomain,
            'shop_id' => is_array($payload) ? ($payload['shop_id'] ?? null) : null,
        ]);

        return $this->success(['received' => true]);
    }
}
