<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\BrandStoreSettings;
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

        // Purge affiliate curated selections for this brand. They reference
        // Shopify product GIDs that will go stale the moment the brand
        // reinstalls (new IDs) or stay stale if they never do — either way
        // they're not meaningful while the integration is torn down.
        //
        // Note: we can't call the Shopify Admin API from here because the
        // access token has already been revoked by Shopify BEFORE this
        // webhook fires. Metafield definitions, collections, and the
        // storefront access token will remain in the brand's store unless
        // they clicked "Disconnect" in the Side St dashboard first (that
        // path runs the full teardown via ShopifyTeardownService while the
        // token is still alive). See docs/brand-catalog-v2.md for the
        // recommended disconnect flow.
        $deletedSelections = AffiliateProductSelection::query()
            ->where('brand_professional_id', $integration->professional_id)
            ->delete();

        // Reset wizard progress so the setup flow starts fresh on reinstall.
        BrandStoreSettings::clearWizardProgress((string) $integration->professional_id);
        BrandProfile::where('professional_id', $integration->professional_id)
            ->update(['setup_complete' => false]);

        Log::info('Shopify app uninstalled — integration disconnected.', [
            'professional_id' => (string) $integration->professional_id,
            'shop_domain' => $shopDomain,
            'deleted_selections' => $deletedSelections,
        ]);

        return $this->success(['received' => true]);
    }
}
