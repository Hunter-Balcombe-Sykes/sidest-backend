<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Receives Shopify app/uninstalled webhooks. Clears access token and marks integration as disconnected.
class ShopifyAppUninstalledWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify app/uninstalled webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            Log::warning('Shopify app/uninstalled webhook: unknown shop domain', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $metadata['disconnected_at'] = now()->toIso8601String();
        $metadata['disconnected_reason'] = 'app_uninstalled';
        $metadata['webhook_registration_state'] = 'uninstalled';
        $metadata['webhooks_state'] = 'uninstalled';

        $integration->update([
            'access_token' => null,
            'refresh_token' => null,
            'provider_metadata' => $metadata,
        ]);

        Log::info('Shopify app uninstalled — integration disconnected.', [
            'professional_id' => (string) $integration->professional_id,
            'shop_domain' => $shopDomain,
        ]);

        return $this->success(['received' => true]);
    }
}
