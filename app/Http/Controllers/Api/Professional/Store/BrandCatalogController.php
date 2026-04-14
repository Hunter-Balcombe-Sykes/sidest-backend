<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Store\ToggleProductActiveRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductCommissionRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductDiscountRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductMetafieldsRequest;
use App\Http\Resources\BrandCatalogProductResource;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandCatalogController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandCatalogService $catalogService
    ) {}

    /**
     * GET /brand/catalog
     *
     * Returns the brand's full Shopify product catalog with sidest.* metafield values.
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        try {
            $products = $this->catalogService->fetchBrandCatalog($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success([
            'products' => BrandCatalogProductResource::collection(collect($products)),
        ]);
    }

    /**
     * GET /brand/catalog/all
     *
     * Returns ALL products from the Shopify store (active, draft, archived)
     * Includes sidest.* metafields (commission, discount, active).
     */
    public function all(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        try {
            $products = $this->catalogService->fetchAllProducts($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success([
            'products' => BrandCatalogProductResource::collection(collect($products)),
        ]);
    }

    /**
     * PATCH /brand/catalog/{productGid}/metafields
     *
     * Batch update active, commission_override, and affiliate_discount_pct on one product.
     */
    public function updateMetafields(UpdateProductMetafieldsRequest $request, string $productGid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        if (! $this->isValidProductGid($productGid)) {
            return $this->error('Invalid product GID format.', 422);
        }

        try {
            $resolved = $this->catalogService->resolveBrandIntegration($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }

        $integration = $resolved['integration'];
        $validated = $request->validated();
        $metafieldsToSet = [];

        if (array_key_exists('active', $validated)) {
            $metafieldsToSet[] = ['key' => 'active', 'value' => $validated['active'] ? 'true' : 'false', 'type' => 'boolean'];
        }

        if (array_key_exists('commission_override', $validated)) {
            if ($validated['commission_override'] === null) {
                try {
                    $this->catalogService->deleteProductMetafield($integration, $productGid, 'commission_override');
                } catch (\Throwable $e) {
                    return $this->error('Unable to reach Shopify. Please try again.', 502);
                }
            } else {
                $metafieldsToSet[] = ['key' => 'commission_override', 'value' => (string) $validated['commission_override'], 'type' => 'number_decimal'];
            }
        }

        if (array_key_exists('affiliate_discount_pct', $validated)) {
            if ($validated['affiliate_discount_pct'] === null) {
                try {
                    $this->catalogService->deleteProductMetafield($integration, $productGid, 'affiliate_discount_pct');
                } catch (\Throwable $e) {
                    return $this->error('Unable to reach Shopify. Please try again.', 502);
                }
            } else {
                $metafieldsToSet[] = ['key' => 'affiliate_discount_pct', 'value' => (string) $validated['affiliate_discount_pct'], 'type' => 'number_decimal'];
            }
        }

        if (array_key_exists('custom_photos_enabled', $validated)) {
            if ($validated['custom_photos_enabled'] === null) {
                try {
                    $this->catalogService->deleteProductMetafield($integration, $productGid, 'custom_photos_enabled');
                } catch (\Throwable $e) {
                    return $this->error('Unable to reach Shopify. Please try again.', 502);
                }
            } else {
                $metafieldsToSet[] = ['key' => 'custom_photos_enabled', 'value' => $validated['custom_photos_enabled'] ? 'true' : 'false', 'type' => 'boolean'];
            }
        }

        if (array_key_exists('enabled_variant_gids', $validated)) {
            $submitted = $validated['enabled_variant_gids'];

            // Null or empty array → clear restriction (default: all variants offered).
            if ($submitted === null || $submitted === []) {
                try {
                    $this->catalogService->deleteProductMetafield($integration, $productGid, 'enabled_variant_gids');
                } catch (\Throwable $e) {
                    return $this->error('Unable to reach Shopify. Please try again.', 502);
                }
            } else {
                try {
                    $productVariantGids = $this->catalogService->fetchProductVariantGids($integration, $productGid);
                } catch (\Throwable $e) {
                    return $this->error('Unable to reach Shopify. Please try again.', 502);
                }

                if (empty($productVariantGids)) {
                    return $this->error('Product has no variants to restrict.', 422);
                }

                $allowed = array_flip($productVariantGids);
                $invalid = array_values(array_filter($submitted, fn (string $gid) => ! isset($allowed[$gid])));

                if (! empty($invalid)) {
                    return $this->error('One or more variant GIDs do not belong to this product.', 422);
                }

                $metafieldsToSet[] = [
                    'key' => 'enabled_variant_gids',
                    'value' => json_encode(array_values($submitted)),
                    'type' => 'json',
                ];
            }
        }

        if (! empty($metafieldsToSet)) {
            try {
                $result = $this->catalogService->setProductMetafields($integration, $productGid, $metafieldsToSet);

                if (! $result['success']) {
                    $msg = $result['userErrors'][0]['message'] ?? 'Failed to update metafields.';

                    return $this->error($msg, 422);
                }
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage(), $e->getCode() ?: 502);
            } catch (\Throwable $e) {
                return $this->error('Unable to reach Shopify. Please try again.', 502);
            }
        }

        return $this->success(['updated' => true]);
    }

    /**
     * PATCH /brand/catalog/{productGid}/active
     *
     * Toggle sidest.active on a product.
     */
    public function toggleActive(ToggleProductActiveRequest $request, string $productGid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        if (! $this->isValidProductGid($productGid)) {
            return $this->error('Invalid product GID format.', 422);
        }

        try {
            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $result = $this->catalogService->setProductMetafields($resolved['integration'], $productGid, [
                ['key' => 'active', 'value' => $request->validated()['active'] ? 'true' : 'false', 'type' => 'boolean'],
            ]);

            if (! $result['success']) {
                $msg = $result['userErrors'][0]['message'] ?? 'Failed to update active status.';

                return $this->error($msg, 422);
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success(['active' => (bool) $request->validated()['active']]);
    }

    /**
     * PATCH /brand/catalog/{productGid}/commission
     *
     * Set or clear sidest.commission_override on a product.
     */
    public function updateCommission(UpdateProductCommissionRequest $request, string $productGid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        if (! $this->isValidProductGid($productGid)) {
            return $this->error('Invalid product GID format.', 422);
        }

        try {
            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $value = $request->validated()['commission_override'];

            if ($value === null) {
                $this->catalogService->deleteProductMetafield($resolved['integration'], $productGid, 'commission_override');
            } else {
                $result = $this->catalogService->setProductMetafields($resolved['integration'], $productGid, [
                    ['key' => 'commission_override', 'value' => (string) $value, 'type' => 'number_decimal'],
                ]);

                if (! $result['success']) {
                    $msg = $result['userErrors'][0]['message'] ?? 'Failed to update commission override.';

                    return $this->error($msg, 422);
                }
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success(['commission_override' => $value]);
    }

    /**
     * PATCH /brand/catalog/{productGid}/discount
     *
     * Set or clear sidest.affiliate_discount_pct on a product.
     */
    public function updateDiscount(UpdateProductDiscountRequest $request, string $productGid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        if (! $this->isValidProductGid($productGid)) {
            return $this->error('Invalid product GID format.', 422);
        }

        try {
            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $value = $request->validated()['affiliate_discount_pct'];

            if ($value === null) {
                $this->catalogService->deleteProductMetafield($resolved['integration'], $productGid, 'affiliate_discount_pct');
            } else {
                $result = $this->catalogService->setProductMetafields($resolved['integration'], $productGid, [
                    ['key' => 'affiliate_discount_pct', 'value' => (string) $value, 'type' => 'number_decimal'],
                ]);

                if (! $result['success']) {
                    $msg = $result['userErrors'][0]['message'] ?? 'Failed to update discount.';

                    return $this->error($msg, 422);
                }
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        return $this->success(['affiliate_discount_pct' => $value]);
    }

    private function isValidProductGid(string $gid): bool
    {
        return (bool) preg_match('/^gid:\/\/shopify\/Product\/\d+$/', $gid);
    }
}
