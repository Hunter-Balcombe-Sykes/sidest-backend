<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\BrandCollectionProductResource;
use App\Models\Core\Professional\Professional;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

// Staff inspector for the products inside a brand's Shopify collection (#COLLECTION-1).
// Mirrors BrandCollectionController::index. Add/remove products are admin writes and
// are intentionally not part of the read-only bundle.
class StaffBrandCollectionController extends ApiController
{
    private const COLLECTION_TYPE_MAP = [
        'active' => 'active_collection_handle',
        'default' => 'default_collection_handle',
        'favourites' => 'favourites_collection_handle',
    ];

    public function __construct(
        private readonly BrandCatalogService $catalogService,
    ) {}

    /**
     * GET /staff/professionals/{professional}/brand/collections/{collectionType}/products
     */
    public function index(Professional $professional, string $collectionType): JsonResponse
    {
        $metadataKey = self::COLLECTION_TYPE_MAP[$collectionType] ?? null;

        if (! $metadataKey) {
            return $this->error('Unknown collection type.', 422);
        }

        try {
            $resolved = $this->catalogService->resolveBrandIntegration($professional);
            $handle = Arr::get($resolved['metadata'], $metadataKey, '');

            // Mirror brand-side behaviour: handle not yet populated → empty list,
            // not 404. The brand sees the same shape during the post-connect
            // provisioning window.
            if ($handle === '') {
                return $this->success(['products' => []]);
            }

            $collectionGid = $this->catalogService->resolveCollectionGid($resolved['integration'], $handle);

            if (! $collectionGid) {
                return $this->success(['products' => []]);
            }

            $products = $this->catalogService->fetchCollectionProducts($resolved['integration'], $collectionGid);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success([
            'products' => BrandCollectionProductResource::collection(collect($products)),
        ]);
    }
}
