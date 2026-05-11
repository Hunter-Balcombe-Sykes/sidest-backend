<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

// V2: Serves Shopify Storefront API credentials (domain + token + collection handle) to Hydrogen storefronts. Accepts shop_domain or brand_slug lookup.
class PublicShopifyStorefrontController extends ApiController
{
    use NormalizesShopDomain;

    /**
     * GET /public/shopify/storefront-config?shop_domain={domain}
     * GET /public/shopify/storefront-config?brand_slug={slug}
     *
     * Returns the Shopify Storefront API credentials for a brand.
     * Called by the Hydrogen storefront at runtime to fetch products.
     * Accepts shop_domain (primary) or brand_slug (backward compat).
     */
    public function storefrontConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'shop_domain' => ['sometimes', 'string', 'max:255'],
            'brand_slug' => ['sometimes', 'string', 'max:120'],
        ]);

        $validator->after(function ($validator) {
            $data = $validator->getData();
            if (empty($data['shop_domain']) && empty($data['brand_slug'])) {
                $validator->errors()->add('shop_domain', 'Either shop_domain or brand_slug is required.');
            }
        });

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $validated = $validator->validated();

        // Resolve integration: shop_domain takes precedence over brand_slug
        $integration = ! empty($validated['shop_domain'])
            ? $this->resolveByShopDomain($validated['shop_domain'])
            : $this->resolveByBrandSlug($validated['brand_slug']);

        if (! $integration) {
            return $this->error('Not found.', 404);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $storefrontToken = trim((string) ($integration->storefront_token ?? ''));

        if ($shopDomain === '') {
            return $this->error('Not found.', 404);
        }

        // Token missing — dispatch creation job (with dedup) so the next request succeeds.
        if ($storefrontToken === '') {
            $jobKey = 'storefront-token-job:'.$integration->id;
            if (! Cache::has($jobKey)) {
                Log::info('Storefront token missing, dispatching creation job.', [
                    'integration_id' => (string) $integration->id,
                ]);
                CreateStorefrontAccessTokenJob::dispatch((string) $integration->id);
                Cache::put($jobKey, true, 600);
            }

            return response()->json([
                'status' => 'pending',
                'message' => 'Storefront token is being created. Try again in a few seconds.',
            ], 202);
        }

        $brandProfile = BrandProfile::where('professional_id', $integration->professional_id)->first();

        return $this->success([
            'shop_domain' => $shopDomain,
            'storefront_access_token' => $storefrontToken,
            'default_collection_handle' => Arr::get($metadata, 'default_collection_handle', 'partna-default-products'),
            'brand_status' => $brandProfile?->brand_status ?? BrandStatus::Onboarding->value,
            'business_website' => $brandProfile?->business_website,
        ]);
    }

    private function resolveByShopDomain(string $shopDomain): ?ProfessionalIntegration
    {
        $normalized = $this->normalizeShopDomain($shopDomain);

        if ($normalized === '') {
            return null;
        }

        return ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('shopify_shop_domain', $normalized)
            ->whereNotNull('access_token')
            ->first();
    }

    private function resolveByBrandSlug(string $brandSlug): ?ProfessionalIntegration
    {
        $slug = strtolower(trim($brandSlug));

        $site = Site::query()
            ->whereRaw('lower(subdomain) = ?', [$slug])
            ->first();

        if (! $site) {
            return null;
        }

        return ProfessionalIntegration::query()
            ->where('professional_id', $site->professional_id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token')
            ->first();
    }
}
