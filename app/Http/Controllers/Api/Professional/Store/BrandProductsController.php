<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\BrandProductSetting;
use App\Services\Store\BrandAccessService;
use App\Services\Store\BrandProductCatalogService;
use App\Services\Store\BrandProductSettingsService;
use App\Services\Store\SelectionCleanupService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class BrandProductsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
        private readonly BrandProductCatalogService $catalog,
        private readonly BrandProductSettingsService $settingsRows,
        private readonly SelectionCleanupService $selectionCleanup
    ) {}

    /**
     * GET /store/brand-products
     * Full brand catalog for brand/distributor managers.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $managedBrandIds = $this->brandAccess->managedBrandIds($professional);

        if ($managedBrandIds === []) {
            return $this->error('You are not permitted to manage any brand catalogs.', 403);
        }

        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $requestedBrandId = trim((string) ($validator->validated()['brand_professional_id'] ?? ''));

        if ($requestedBrandId !== '' && ! in_array($requestedBrandId, $managedBrandIds, true)) {
            return $this->error('You are not permitted to manage this brand catalog.', 403);
        }

        $targetBrandIds = $requestedBrandId !== '' ? [$requestedBrandId] : $managedBrandIds;
        $products = $this->catalog->managedCatalog($targetBrandIds);

        return $this->success([
            'managed_brand_ids' => $targetBrandIds,
            'brand_products' => $products,
        ]);
    }

    /**
     * PATCH /store/brand-products/{brandProductId}
     */
    public function update(Request $request, string $brandProductId)
    {
        $professional = $this->currentProfessional($request);
        $brandProduct = BrandProduct::query()->find($brandProductId);

        if (! $brandProduct) {
            return $this->error('Brand product not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($professional, (string) $brandProduct->brand_professional_id)) {
            return $this->error('You are not permitted to manage this brand product.', 403);
        }

        $validator = Validator::make($request->all(), $this->settingsPatchRules());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $patch = $this->extractPatchAttributes($validated);
        if ($patch === []) {
            return $this->error('No updates were provided.', 422);
        }

        $cleanupCount = 0;

        try {
            DB::transaction(function () use ($brandProduct, $patch, &$cleanupCount): void {
                $this->settingsRows->ensureSettingsRowsForBrand((string) $brandProduct->brand_professional_id);

                $settings = BrandProductSetting::query()
                    ->where('professional_id', $brandProduct->brand_professional_id)
                    ->where('brand_product_id', $brandProduct->id)
                    ->lockForUpdate()
                    ->first();

                if (! $settings) {
                    $settings = BrandProductSetting::query()->create([
                        'professional_id' => (string) $brandProduct->brand_professional_id,
                        'brand_product_id' => (string) $brandProduct->id,
                        'shopify_product_id' => (string) $brandProduct->shopify_product_id,
                        'is_approved' => false,
                        'is_featured' => false,
                        'is_available' => true,
                        'sort_order' => 0,
                    ]);
                }

                $settings->fill($patch);
                $settings->save();

                $isApproved = (bool) $settings->is_approved;
                $isAvailable = (bool) $settings->is_available;

                if (! $isApproved || ! $isAvailable) {
                    $cleanupCount = $this->selectionCleanup->removeSelectionsForBrandProducts(
                        [(string) $brandProduct->id],
                        'Product selections removed',
                        '{count} selected product(s) were removed because the brand changed product approval or availability.'
                    );
                }
            });
        } catch (Throwable $e) {
            return $this->handleMutationException($e);
        }

        $product = collect($this->catalog->managedCatalog([(string) $brandProduct->brand_professional_id]))
            ->first(static fn (array $row): bool => ($row['brand_product_id'] ?? '') === (string) $brandProduct->id);

        return $this->success([
            'brand_product' => $product,
            'cleaned_selection_count' => $cleanupCount,
        ]);
    }

    /**
     * PATCH /store/brand-products/bulk
     */
    public function bulkUpdate(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['required', 'uuid'],
            'brand_product_ids' => ['sometimes', 'array'],
            'brand_product_ids.*' => ['uuid'],
            ...$this->settingsPatchRules(),
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $brandProfessionalId = trim((string) $validated['brand_professional_id']);

        if (! $this->brandAccess->canManageBrand($professional, $brandProfessionalId)) {
            return $this->error('You are not permitted to manage this brand catalog.', 403);
        }

        $patch = $this->extractPatchAttributes($validated);
        if ($patch === []) {
            return $this->error('No updates were provided.', 422);
        }

        $requestedIds = collect($validated['brand_product_ids'] ?? [])
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        if (array_key_exists('brand_product_ids', $validated) && $requestedIds === []) {
            return $this->error('brand_product_ids cannot be empty when provided.', 422);
        }

        $updatedIds = [];
        $cleanupIds = [];
        $cleanupCount = 0;

        try {
            DB::transaction(function () use (
                $brandProfessionalId,
                $requestedIds,
                $patch,
                &$updatedIds,
                &$cleanupIds,
                &$cleanupCount
            ): void {
                $productsQuery = BrandProduct::query()
                    ->where('brand_professional_id', $brandProfessionalId)
                    ->lockForUpdate();

                if ($requestedIds !== []) {
                    $productsQuery->whereIn('id', $requestedIds);
                }

                $products = $productsQuery->get(['id', 'brand_professional_id', 'shopify_product_id']);
                if ($products->isEmpty()) {
                    throw ValidationException::withMessages([
                        'brand_product_ids' => 'No matching brand products were found for this brand.',
                    ]);
                }

                $this->settingsRows->ensureSettingsRowsForBrand($brandProfessionalId);

                $productIds = $products
                    ->pluck('id')
                    ->map(static fn ($id): string => (string) $id)
                    ->values()
                    ->all();

                $settingsByProductId = BrandProductSetting::query()
                    ->where('professional_id', $brandProfessionalId)
                    ->whereIn('brand_product_id', $productIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('brand_product_id');

                foreach ($products as $product) {
                    $settings = $settingsByProductId->get((string) $product->id);

                    if (! $settings) {
                        $settings = BrandProductSetting::query()->create([
                            'professional_id' => (string) $product->brand_professional_id,
                            'brand_product_id' => (string) $product->id,
                            'shopify_product_id' => (string) $product->shopify_product_id,
                            'is_approved' => false,
                            'is_featured' => false,
                            'is_available' => true,
                            'sort_order' => 0,
                        ]);
                    }

                    $settings->fill($patch);
                    $settings->save();

                    $updatedIds[] = (string) $product->id;

                    if (! (bool) $settings->is_approved || ! (bool) $settings->is_available) {
                        $cleanupIds[] = (string) $product->id;
                    }
                }

                $cleanupIds = array_values(array_unique($cleanupIds));
                if ($cleanupIds !== []) {
                    $cleanupCount = $this->selectionCleanup->removeSelectionsForBrandProducts(
                        $cleanupIds,
                        'Product selections removed',
                        '{count} selected product(s) were removed because the brand changed product approval or availability.'
                    );
                }
            });
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            return $this->handleMutationException($e);
        }

        return $this->success([
            'brand_professional_id' => $brandProfessionalId,
            'updated_count' => count($updatedIds),
            'updated_brand_product_ids' => array_values(array_unique($updatedIds)),
            'cleaned_selection_count' => $cleanupCount,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function settingsPatchRules(): array
    {
        return [
            'is_approved' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'commission_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'discount_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'custom_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function extractPatchAttributes(array $validated): array
    {
        $allowed = [
            'is_approved',
            'is_available',
            'is_featured',
            'sort_order',
            'commission_override',
            'discount_rate',
            'custom_price',
        ];

        $patch = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $validated)) {
                $patch[$key] = $validated[$key];
            }
        }

        return $patch;
    }

    private function handleMutationException(Throwable $e)
    {
        if ($e instanceof QueryException) {
            $sqlState = (string) $e->getCode();

            if ($sqlState === '23514') {
                return $this->error('Brand product update failed validation.', 422);
            }

            if ($sqlState === '23505') {
                return $this->error('Brand product update created duplicate rows.', 422);
            }
        }

        return $this->error('Failed to update brand products.', 500);
    }
}
