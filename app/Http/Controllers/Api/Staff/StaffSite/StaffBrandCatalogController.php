<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Professional\Store\BrandCatalogController;
use App\Http\Requests\Api\Professional\Store\ToggleProductActiveRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductCommissionRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductDiscountRequest;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff inspector for a brand's Shopify catalog (#CATALOG-1).
// Delegates to the brand-side BrandCatalogController so the payload, cache layer,
// and Shopify rate-limit accounting stay shared. Catalog endpoints hit Shopify
// GraphQL on cache miss — sharing the same cache key prevents staff from burning
// the brand's Shopify rate-limit budget.
class StaffBrandCatalogController extends ApiController
{
    public function __construct(
        private readonly BrandCatalogController $delegate,
    ) {}

    /**
     * GET /staff/professionals/{professional}/brand/catalog
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $request->attributes->set('professional', $professional);

        return $this->delegate->index($request);
    }

    /**
     * GET /staff/professionals/{professional}/brand/catalog/all
     */
    public function all(Request $request, Professional $professional): JsonResponse
    {
        $request->attributes->set('professional', $professional);

        return $this->delegate->all($request);
    }

    /**
     * GET /staff/professionals/{professional}/brand/catalog/debug
     */
    public function debug(Request $request, Professional $professional): JsonResponse
    {
        $request->attributes->set('professional', $professional);

        return $this->delegate->debug($request);
    }

    /**
     * PATCH /staff/professionals/{professional}/brand/catalog/{productGid}/commission (#CATALOG-2)
     */
    public function updateCommission(UpdateProductCommissionRequest $request, Professional $professional, string $productGid): JsonResponse
    {
        $request->attributes->set('professional', $professional);

        return $this->delegate->updateCommission($request, $productGid);
    }

    /**
     * PATCH /staff/professionals/{professional}/brand/catalog/{productGid}/discount (#CATALOG-2)
     */
    public function updateDiscount(UpdateProductDiscountRequest $request, Professional $professional, string $productGid): JsonResponse
    {
        $request->attributes->set('professional', $professional);

        return $this->delegate->updateDiscount($request, $productGid);
    }

    /**
     * PATCH /staff/professionals/{professional}/brand/catalog/{productGid}/active (#CATALOG-2)
     */
    public function toggleActive(ToggleProductActiveRequest $request, Professional $professional, string $productGid): JsonResponse
    {
        $request->attributes->set('professional', $professional);

        return $this->delegate->toggleActive($request, $productGid);
    }
}
