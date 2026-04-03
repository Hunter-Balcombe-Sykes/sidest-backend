<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Internal endpoint for Hydrogen loaders. Returns ordered product GIDs for an affiliate, or brand default collection as fallback.
class HydrogenAffiliateProductsController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'affiliate_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $affiliateId = $validator->validated()['affiliate_id'];

        // Get affiliate's selected products ordered by sort_order
        $selections = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->orderBy('sort_order')
            ->pluck('shopify_product_gid')
            ->all();

        if (! empty($selections)) {
            return $this->success([
                'gids' => $selections,
                'source' => 'affiliate_selections',
            ]);
        }

        // Fallback: return brand's default collection handle so Hydrogen can query it via Storefront API
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        if (! $link) {
            return $this->error('Affiliate not linked to any brand.', 404);
        }

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $link->brand_professional_id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];

        return $this->success([
            'gids' => [],
            'source' => 'default_collection',
            'default_collection_handle' => Arr::get($metadata, 'default_collection_handle', 'sidest-default-products'),
        ]);
    }
}
