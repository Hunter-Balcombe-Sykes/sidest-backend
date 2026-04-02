<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PublicShopifyStorefrontController extends ApiController
{
    /**
     * GET /public/shopify/storefront-config?brand_slug={slug}
     *
     * Returns the Shopify Storefront API credentials for a brand,
     * keyed by their site subdomain. Called by the Hydrogen storefront
     * at runtime to fetch products for an affiliate page.
     */
    public function storefrontConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'brand_slug' => ['required', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $brandSlug = strtolower(trim((string) $validator->validated()['brand_slug']));

        // Try exact subdomain match first; fall back to unpublished so we can
        // still serve the storefront even if the brand's site isn't published.
        $site = Site::query()
            ->whereRaw('lower(subdomain) = ?', [$brandSlug])
            ->first();

        if (! $site) {
            return $this->error('Brand not found.', 404);
        }

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $site->professional_id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration || empty($integration->access_token)) {
            return $this->error('Shopify not connected for this brand.', 404);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $storefrontToken = trim((string) Arr::get($metadata, 'storefront_access_token', ''));

        if ($shopDomain === '') {
            return $this->error('Shopify storefront not configured for this brand.', 404);
        }

        // Token missing — the job hasn't run yet (e.g. brand connected before this feature
        // was deployed). Dispatch it now so the next request will succeed.
        if ($storefrontToken === '') {
            Log::info('Storefront token missing, dispatching creation job.', [
                'integration_id' => (string) $integration->id,
                'brand_slug' => $brandSlug,
            ]);
            CreateStorefrontAccessTokenJob::dispatch((string) $integration->id);

            return response()->json([
                'status' => 'pending',
                'message' => 'Storefront token is being created. Try again in a few seconds.',
            ], 202);
        }

        return $this->success([
            'shop_domain' => $shopDomain,
            'storefront_access_token' => $storefrontToken,
        ]);
    }
}
