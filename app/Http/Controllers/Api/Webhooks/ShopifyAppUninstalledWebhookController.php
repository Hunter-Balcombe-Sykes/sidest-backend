<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Jobs\Shopify\PurgeAffiliateProductSelectionsJob;
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

            return $this->error('invalid signature', 401);
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

        // Preserve pre-uninstall state so the brand can resume where they left off
        // on reinstall. Wizard flags in brand_store_settings are intentionally NOT
        // cleared — the Shopify-side resources (collections, metafields) may still
        // exist, and the BrandSignupService will re-evaluate them on reinstall.
        $brandProfile = BrandProfile::where('professional_id', $integration->professional_id)->first();
        $storeSettings = BrandStoreSettings::where('professional_id', $integration->professional_id)->first();

        $metadata['uninstalled_from_status'] = $brandProfile?->brand_status ?? 'onboarding';
        $metadata['uninstalled_wizard_state'] = [
            'hydrogen_install_confirmed' => (bool) ($storeSettings?->hydrogen_install_confirmed ?? false),
            'oxygen_deployment_token_set' => ! empty($storeSettings?->getRawOriginal('oxygen_deployment_token')),
            'oxygen_storefront_id_present' => ! empty($storeSettings?->oxygen_storefront_id),
        ];

        $integration->update([
            'access_token' => null,
            'refresh_token' => null,
            'provider_metadata' => $metadata,
        ]);

        // Set brand to disconnected without clearing wizard progress — preserve wizard
        // state so the brand doesn't have to re-enter profile/business details on reinstall.
        BrandProfile::where('professional_id', $integration->professional_id)
            ->update([
                'brand_status' => BrandStatus::Disconnected->value,
                'setup_complete' => false,
            ]);

        // Purge affiliate curated selections for this brand in a chunked queue
        // job. Master Pattern 16 (DB-F#SCALE-3): a single-statement delete on a
        // brand with thousands of selections held row locks long enough to
        // block concurrent orders/paid commission lookups. The job chunks by
        // primary-key cursor (CHUNK_SIZE = 500), so no single chunk holds
        // locks for more than ~500 rows.
        //
        // Why purge at all: the selections reference Shopify product GIDs that
        // will go stale the moment the brand reinstalls (new IDs) or stay stale
        // if they never do — either way they're not meaningful while the
        // integration is torn down.
        //
        // Note: we can't call the Shopify Admin API from here because the
        // access token has already been revoked by Shopify BEFORE this webhook
        // fires. Metafield definitions, collections, and the storefront access
        // token will remain in the brand's store unless they clicked
        // "Disconnect" in the Partna dashboard first (that path runs the full
        // teardown via ShopifyTeardownService while the token is still alive).
        // See docs/brand-catalog-v2.md for the recommended disconnect flow.
        PurgeAffiliateProductSelectionsJob::dispatch((string) $integration->professional_id);

        Log::info('Shopify app uninstalled — integration disconnected.', [
            'professional_id' => (string) $integration->professional_id,
            'shop_domain' => $shopDomain,
        ]);

        return $this->success(['received' => true]);
    }
}
