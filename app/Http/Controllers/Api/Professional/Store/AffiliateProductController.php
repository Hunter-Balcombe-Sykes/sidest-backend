<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Store\ReorderSelectionsRequest;
use App\Http\Resources\AffiliateProductResource;
use App\Http\Resources\AffiliateProductSelectionResource;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AffiliateProductController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly AffiliateProductCatalogService $catalogService
    ) {}

    /**
     * GET /affiliate/products
     *
     * Returns the brand's Shopify catalog with the affiliate's selection state merged in.
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if ($pro->isBrand()) {
            return $this->error('Brand accounts cannot manage product selections.', 403);
        }

        try {
            $result = $this->catalogService->getCatalogWithSelections($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach product catalog. Please try again.', 502);
        }

        return $this->success([
            'products' => AffiliateProductResource::collection(collect($result['products'])),
            'brand_professional_id' => $result['brand_professional_id'],
            'default_commission_rate' => $result['default_commission_rate'] ?? 15,
        ]);
    }

    /**
     * GET /affiliate/selections/stale
     *
     * Returns selections whose product GIDs are no longer in the brand's active catalog.
     */
    public function stale(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if ($pro->isBrand()) {
            return $this->error('Brand accounts cannot manage product selections.', 403);
        }

        try {
            $stale = $this->catalogService->getStaleSelections($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach product catalog. Please try again.', 502);
        }

        return $this->success([
            'stale' => AffiliateProductSelectionResource::collection($stale),
        ]);
    }

    /**
     * POST /affiliate/selections
     *
     * Add a product to the affiliate's selections. Requires brand_professional_id
     * to scope the selection to a specific brand the affiliate is linked to.
     */
    public function store(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if ($pro->isBrand()) {
            return $this->error('Brand accounts cannot manage product selections.', 403);
        }

        $validated = $request->validate([
            'brand_professional_id' => ['required', 'uuid'],
            'shopify_product_gid' => ['required', 'string', 'max:100', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);

        $linked = DB::table('brand.brand_partner_links')
            ->where('affiliate_professional_id', $pro->id)
            ->where('brand_professional_id', $validated['brand_professional_id'])
            ->exists();

        if (! $linked) {
            return $this->error('You are not linked to this brand.', 422);
        }

        // Verify product exists in the brand's active catalog
        try {
            if (! $this->catalogService->isProductInCatalog($validated['brand_professional_id'], $validated['shopify_product_gid'])) {
                return $this->error('This product is not available for selection.', 422);
            }
        } catch (\Throwable $e) {
            return $this->error('Unable to reach product catalog. Please try again.', 502);
        }

        $max = (int) config('sidest.store.max_featured_products', 10);

        $currentCount = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('brand_professional_id', $validated['brand_professional_id'])
            ->count();

        if ($currentCount >= $max) {
            return $this->error("Maximum of {$max} selections allowed.", 422);
        }

        try {
            $selection = DB::transaction(function () use ($pro, $validated) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["aff-sel:{$pro->id}"]);

                return AffiliateProductSelection::create([
                    'affiliate_professional_id' => $pro->id,
                    'brand_professional_id' => $validated['brand_professional_id'],
                    'shopify_product_gid' => $validated['shopify_product_gid'],
                    'sort_order' => $validated['sort_order'] ?? 0,
                ]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return $this->error('This product is already selected.', 409);
            }
            throw $e;
        }

        return $this->success([
            'selection' => new AffiliateProductSelectionResource($selection),
        ], 201);
    }

    /**
     * DELETE /affiliate/selections/{gid}
     *
     * Remove a selection by Shopify product GID.
     */
    public function destroy(Request $request, string $gid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if ($pro->isBrand()) {
            return $this->error('Brand accounts cannot manage product selections.', 403);
        }

        // Validate GID format
        if (! preg_match('/^gid:\/\/shopify\/Product\/\d+$/', $gid)) {
            return $this->error('Invalid product GID format.', 422);
        }

        $selection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->first();

        if (! $selection) {
            return $this->error('Selection not found.', 404);
        }

        $selection->delete();

        // Clean up custom product photos for the deselected product
        $site = Site::where('professional_id', $pro->id)->first();
        if ($site) {
            $orphanedPhotos = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_PRODUCT)
                ->where('product_gid', $gid)
                ->get();

            if ($orphanedPhotos->isNotEmpty()) {
                $imageService = app(ImageVariantService::class);
                foreach ($orphanedPhotos as $photo) {
                    $imageService->deleteVariants($photo->id, $photo->path);
                    $photo->delete();
                }
            }
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * PATCH /affiliate/selections/reorder
     *
     * Bulk-update sort_order for the affiliate's selections.
     */
    public function reorder(ReorderSelectionsRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if ($pro->isBrand()) {
            return $this->error('Brand accounts cannot manage product selections.', 403);
        }

        $items = $request->validated()['items'];
        $gids = array_column($items, 'product_gid');

        DB::transaction(function () use ($pro, $items, $gids) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["aff-sel:{$pro->id}"]);

            // Verify all GIDs belong to this affiliate
            $existingCount = AffiliateProductSelection::query()
                ->where('affiliate_professional_id', $pro->id)
                ->whereIn('shopify_product_gid', $gids)
                ->count();

            if ($existingCount !== count($gids)) {
                abort(422, 'One or more product GIDs do not belong to your selections.');
            }

            foreach ($items as $item) {
                AffiliateProductSelection::query()
                    ->where('affiliate_professional_id', $pro->id)
                    ->where('shopify_product_gid', $item['product_gid'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return $this->success(['ok' => true]);
    }

    /**
     * POST /affiliate/selections/reset-to-defaults
     *
     * Clears the affiliate's current selections and reseeds from the brand's default collection.
     * Pass brand_professional_id to reset a single brand; omit to reset all linked brands.
     */
    public function resetToDefaults(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if ($pro->isBrand()) {
            return $this->error('Brand accounts cannot manage product selections.', 403);
        }

        $data = $request->validate([
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if (isset($data['brand_professional_id'])) {
            try {
                $this->catalogService->seedDefaultSelections($pro, $data['brand_professional_id'], clearExisting: true);
            } catch (\Throwable $e) {
                return $this->error('Unable to reset selections. Please try again.', 502);
            }
            return $this->success(['reset' => true, 'brand_professional_id' => $data['brand_professional_id']]);
        }

        // No brand specified — reset across all linked brands.
        $brandIds = DB::table('brand.brand_partner_links')
            ->where('affiliate_professional_id', $pro->id)
            ->pluck('brand_professional_id');

        foreach ($brandIds as $brandId) {
            try {
                $this->catalogService->seedDefaultSelections($pro, (string) $brandId, clearExisting: true);
            } catch (\Throwable $e) {
                // Log but continue with remaining brands
            }
        }

        return $this->success(['reset' => true, 'brand_count' => $brandIds->count()]);
    }
}
