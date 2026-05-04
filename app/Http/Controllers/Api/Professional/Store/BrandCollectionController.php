<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Store\ManageCollectionProductsRequest;
use App\Http\Resources\BrandCollectionProductResource;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class BrandCollectionController extends ApiController
{
    use ResolveCurrentProfessional;

    private const COLLECTION_TYPE_MAP = [
        'active' => 'active_collection_handle',
        'default' => 'default_collection_handle',
        'favourites' => 'favourites_collection_handle',
    ];

    public function __construct(
        private readonly BrandCatalogService $catalogService
    ) {}

    /**
     * GET /brand/collections/{collectionType}/products
     *
     * List products in a manual collection (default or favourites).
     */
    public function index(Request $request, string $collectionType): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        try {
            $collectionGid = $this->resolveCollectionGidFromType($pro, $collectionType);

            // Handle not yet populated — Shopify setup jobs are still running.
            // Return empty rather than 404 so the dashboard doesn't show errors
            // during the post-connect provisioning window.
            if (! $collectionGid) {
                return $this->success(['products' => []]);
            }

            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $products = $this->catalogService->fetchCollectionProducts($resolved['integration'], $collectionGid);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success([
            'products' => BrandCollectionProductResource::collection(collect($products)),
        ]);
    }

    /**
     * POST /brand/collections/{collectionType}/products
     *
     * Add products to a manual collection.
     */
    public function addProducts(ManageCollectionProductsRequest $request, string $collectionType): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        try {
            $collectionGid = $this->resolveCollectionGidFromType($pro, $collectionType);

            if (! $collectionGid) {
                return $this->error('Collection not found.', 404);
            }

            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $productGids = $request->validated()['product_gids'];

            $result = $this->catalogService->addProductsToCollection($resolved['integration'], $collectionGid, $productGids);

            if (! $result['success']) {
                $msg = $result['userErrors'][0]['message'] ?? 'Failed to add products to collection.';

                return $this->error($msg, 422);
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success(['added' => count($productGids)]);
    }

    /**
     * DELETE /brand/collections/{collectionType}/products
     *
     * Remove products from a manual collection.
     */
    public function removeProducts(ManageCollectionProductsRequest $request, string $collectionType): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        try {
            $collectionGid = $this->resolveCollectionGidFromType($pro, $collectionType);

            if (! $collectionGid) {
                return $this->error('Collection not found.', 404);
            }

            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $productGids = $request->validated()['product_gids'];

            $result = $this->catalogService->removeProductsFromCollection($resolved['integration'], $collectionGid, $productGids);

            if (! $result['success']) {
                $msg = $result['userErrors'][0]['message'] ?? 'Failed to remove products from collection.';

                return $this->error($msg, 422);
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success(['removed' => count($productGids)]);
    }

    /**
     * Resolve a collection GID from the type string and the brand's provider_metadata.
     */
    private function resolveCollectionGidFromType($pro, string $collectionType): ?string
    {
        $metadataKey = self::COLLECTION_TYPE_MAP[$collectionType] ?? null;

        if (! $metadataKey) {
            return null;
        }

        $resolved = $this->catalogService->resolveBrandIntegration($pro);
        $handle = Arr::get($resolved['metadata'], $metadataKey, '');

        if ($handle === '') {
            return null;
        }

        return $this->catalogService->resolveCollectionGid($resolved['integration'], $handle);
    }
}
