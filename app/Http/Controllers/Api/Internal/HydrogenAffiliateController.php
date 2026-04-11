<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Internal endpoint for Hydrogen loaders. Validates an affiliate slug belongs to a brand and returns the affiliate record.
class HydrogenAffiliateController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'shop_domain' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $shopDomain = strtolower(trim($validated['shop_domain']));
        $slug = strtolower(trim($validated['slug']));

        // Find the brand by shop domain
        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('Brand not found.', 404);
        }

        // Find the affiliate by slug
        $affiliate = Professional::query()
            ->where('handle_lc', $slug)
            ->whereNull('deleted_at')
            ->first();

        if (! $affiliate || $affiliate->status !== 'active') {
            return $this->error('Affiliate not found.', 404);
        }

        // Verify affiliate is linked to this brand
        $linked = BrandPartnerLink::query()
            ->where('brand_professional_id', $integration->professional_id)
            ->where('affiliate_professional_id', $affiliate->id)
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate not found.', 404);
        }

        $affiliateSite = Site::where('professional_id', $affiliate->id)->first();
        $gallery = $this->getAffiliateGallery($affiliateSite);

        return $this->success([
            'affiliate_id' => (string) $affiliate->id,
            'name' => $affiliate->display_name,
            'slug' => $affiliate->handle,
            'has_gallery' => ! empty($gallery),
            'gallery' => $gallery,
        ]);
    }

    private function getAffiliateGallery(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        return SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteMedia $media) => [
                'url' => $media->variantUrls()['optimized'] ?? null,
                'alt_text' => $media->alt_text,
            ])
            ->filter(fn (array $item) => $item['url'] !== null)
            ->values()
            ->all();
    }
}
