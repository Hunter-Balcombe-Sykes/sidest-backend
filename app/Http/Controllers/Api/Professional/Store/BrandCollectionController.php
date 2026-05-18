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
            report($e);

            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success([
            'products' => BrandCollectionProductResource::collection(collect($products)),
        ]);
    }

    /**
     * POST /brand/collections/{collectionType}/products
     *
     * Add products to a manual collection. Pre-filters product_gids against
     * the collection's current membership so a duplicate-add is a no-op
     * rather than a 422 — Shopify's collectionAddProducts mutation returns
     * a generic "Error adding {gid} to collection" userError when a product
     * is already a member, which is indistinguishable from real failures
     * and breaks the bulk-action UX for the rest of the selection.
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

            // Skip products that are already in the collection.
            $existing = collect($this->catalogService->fetchCollectionProducts($resolved['integration'], $collectionGid))
                ->pluck('gid')
                ->filter()
                ->all();
            $existingSet = array_flip($existing);
            $toAdd = array_values(array_filter(
                $productGids,
                static fn (string $gid): bool => ! isset($existingSet[$gid])
            ));
            $skipped = count($productGids) - count($toAdd);

            if (empty($toAdd)) {
                return $this->success(['added' => 0, 'skipped' => $skipped]);
            }

            $result = $this->catalogService->addProductsToCollection($resolved['integration'], $collectionGid, $toAdd);

            if (! $result['success']) {
                $msg = $result['userErrors'][0]['message'] ?? 'Failed to add products to collection.';

                return $this->error($msg, 422);
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success(['added' => count($toAdd), 'skipped' => $skipped]);
    }

    /**
     * DELETE /brand/collections/{collectionType}/products
     *
     * Remove products from a manual collection. Pre-filters product_gids to
     * only those that are currently in the collection — same rationale as
     * addProducts: Shopify returns generic userErrors on misses that break
     * the bulk-action UX. A miss is a no-op, not a 422.
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

            $existing = collect($this->catalogService->fetchCollectionProducts($resolved['integration'], $collectionGid))
                ->pluck('gid')
                ->filter()
                ->all();
            $existingSet = array_flip($existing);
            $toRemove = array_values(array_filter(
                $productGids,
                static fn (string $gid): bool => isset($existingSet[$gid])
            ));
            $skipped = count($productGids) - count($toRemove);

            if (empty($toRemove)) {
                return $this->success(['removed' => 0, 'skipped' => $skipped]);
            }

            $result = $this->catalogService->removeProductsFromCollection($resolved['integration'], $collectionGid, $toRemove);

            if (! $result['success']) {
                $msg = $result['userErrors'][0]['message'] ?? 'Failed to remove products from collection.';

                return $this->error($msg, 422);
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success(['removed' => count($toRemove), 'skipped' => $skipped]);
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
