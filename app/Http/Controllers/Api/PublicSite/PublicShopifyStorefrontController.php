<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        $site = Site::query()
            ->whereRaw('lower(subdomain) = ?', [$brandSlug])
            ->where('is_published', true)
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

        if ($shopDomain === '' || $storefrontToken === '') {
            return $this->error('Shopify storefront not configured for this brand.', 404);
        }

        return $this->success([
            'shop_domain' => $shopDomain,
            'storefront_access_token' => $storefrontToken,
        ]);
    }
}
