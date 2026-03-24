<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\ProfessionalSelection;
use App\Services\Cache\SiteCacheService;
use App\Services\Professional\ConfirmationPreferenceService;
use App\Services\Store\BrandProductCatalogService;
use App\Services\Store\FeaturedProductsPayloadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class FeaturedProductsController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly FeaturedProductsPayloadService $featuredProductsPayloads,
        private readonly BrandProductCatalogService $catalog
    ) {}

    /**
     * GET /store/featured-products
     * Returns the professional's default product selections (selected and validated storefront products).
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $this->currentSite($professional);

        return $this->success($this->safeFeaturedProductsPayload(
            (string) $professional->id,
            'professional_store_index'
        ));
    }

    /**
     * GET /store/available-products
     * Returns affiliate-visible products across connected brands.
     */
    public function availableProducts(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $brandProfessionalId = trim((string) ($validator->validated()['brand_professional_id'] ?? ''));

        $products = $this->catalog->affiliateVisibleProducts(
            (string) $professional->id,
            $brandProfessionalId !== '' ? $brandProfessionalId : null
        );
        $products = array_values(array_filter(
            $products,
            static fn (array $product): bool => (bool) ($product['is_available'] ?? false)
        ));

        return $this->success([
            'available_products' => $products,
            'max_featured_products' => (int) config('comet.store.max_featured_products', 10),
        ]);
    }

    /**
     * PUT /store/featured-products
     * Hard cutover payload:
     * Expects: { selected_products: [ { brand_product_id, sort_order? }, ... ] }
     */
    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $professionalId = (string) $professional->id;
        $maxFeatured = (int) config('comet.store.max_featured_products', 10);

        if ($request->has('products')) {
            return $this->error(
                'Legacy featured-products payload is no longer supported. Use selected_products[{brand_product_id, sort_order}] (default product selections).',
                422
            );
        }

        $validator = Validator::make($request->all(), [
            'selected_products' => ['required', 'array', 'max:'.$maxFeatured],
            'selected_products.*.brand_product_id' => ['required', 'uuid'],
            'selected_products.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (! $this->featuredProductsPayloads->hasSelectionsTable()) {
            Log::error('Featured products update blocked: retail.professional_selections table is unavailable.', [
                'professional_id' => (string) $professional->id,
                'site_id' => (string) $site->id,
            ]);

            return $this->error(
                'Default product selections table is unavailable. Run retail schema migrations and try again.',
                503
            );
        }

        $existingProductIds = ProfessionalSelection::query()
            ->where('professional_id', $professionalId)
            ->pluck('brand_product_id')
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        $incomingProductIds = collect($validated['selected_products'])
            ->map(fn ($product): string => strtolower(trim((string) ($product['brand_product_id'] ?? ''))))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if (count($incomingProductIds) !== count($validated['selected_products'])) {
            return $this->error('Duplicate brand products are not allowed.', 422);
        }

        $brandProducts = BrandProduct::query()
            ->whereIn('id', $incomingProductIds)
            ->get(['id', 'brand_professional_id'])
            ->keyBy(static fn (BrandProduct $product): string => strtolower((string) $product->id));

        if ($brandProducts->count() !== count($incomingProductIds)) {
            return $this->error('One or more selected products were not found.', 422);
        }

        $unselectedProductDetected = count(array_diff($existingProductIds, $incomingProductIds)) > 0;

        try {
            DB::transaction(function () use ($professional, $validated, $brandProducts) {
                ProfessionalSelection::where('professional_id', $professional->id)->delete();

                foreach ($validated['selected_products'] as $index => $product) {
                    $brandProductId = strtolower(trim((string) $product['brand_product_id']));
                    $brandProduct = $brandProducts->get($brandProductId);

                    $attributes = [
                        'professional_id' => $professional->id,
                        'brand_product_id' => $brandProductId,
                        'brand_professional_id' => (string) $brandProduct->brand_professional_id,
                        'sort_order' => $product['sort_order'] ?? $index,
                    ];

                    ProfessionalSelection::create($attributes);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Featured products retail write failed (update).', [
                'professional_id' => (string) $professional->id,
                'site_id' => (string) $site->id,
                'error' => $e->getMessage(),
            ]);

            $status = 500;
            $message = 'Failed to save default product selections to retail.professional_selections.';

            if ($e instanceof QueryException) {
                $sqlState = (string) $e->getCode();
                if ($sqlState === '23505') {
                    $status = 422;
                    $message = 'Duplicate default product selections are not allowed.';
                } elseif ($sqlState === '23514') {
                    $status = 422;
                    $dbMessage = strtolower($e->getMessage());

                    if (str_contains($dbMessage, 'maximum of')) {
                        $message = 'Too many default product selections selected.';
                    } elseif (str_contains($dbMessage, 'not approved/available') || str_contains($dbMessage, 'not available')) {
                        $message = 'One or more selected products are unavailable.';
                    } elseif (str_contains($dbMessage, 'not connected to selected brand')) {
                        $message = 'You can only select products from connected brands.';
                    } elseif (str_contains($dbMessage, 'denied for this affiliate')) {
                        $message = 'One or more selected products are restricted for your account.';
                    } else {
                        $message = 'Default product selections failed catalog validation.';
                    }
                } elseif ($sqlState === '23503') {
                    $status = 422;
                    $message = 'One or more selected products no longer exist.';
                }
            }

            if (config('app.debug')) {
                $message .= ' '.$e->getMessage();
            }

            return $this->error($message, $status);
        }

        if ($unselectedProductDetected && $this->shouldRememberConfirmationPreference($request)) {
            app(ConfirmationPreferenceService::class)->enableForProfessional(
                $professionalId,
                ConfirmationPreferenceService::ACTION_UNSELECT_PRODUCT
            );
        }

        try {
            app(SiteCacheService::class)->invalidateSite($site);
        } catch (Throwable $e) {
            Log::warning('Site cache invalidation failed after featured products update.', [
                'professional_id' => (string) $professional->id,
                'site_id' => (string) $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success($this->safeFeaturedProductsPayload(
            $professionalId,
            'professional_store_update'
        ));
    }

    /**
     * @return array{
     *   selected_products: array<int, array<string, mixed>>,
     *   default_product_selections: array<int, array<string, mixed>>,
     *   default_commission_rate: float,
     *   max_featured_products: int,
     *   max_default_product_selections: int,
     *   checkout_mode: string
     * }
     */
    private function safeFeaturedProductsPayload(string $professionalId, string $context): array
    {
        try {
            return $this->featuredProductsPayloads->build($professionalId, $context);
        } catch (Throwable $e) {
            Log::error('Featured products payload build failed; falling back to minimal selection payload.', [
                'professional_id' => $professionalId,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            $maxFeatured = (int) config('comet.store.max_featured_products', 10);

            $selectedProducts = [];
            try {
                $selectedProducts = array_slice(
                    $this->catalog->selectedProductsForProfessional($professionalId),
                    0,
                    $maxFeatured
                );
            } catch (Throwable $catalogError) {
                Log::warning('Featured products catalog fallback also failed.', [
                    'professional_id' => $professionalId,
                    'context' => $context,
                    'error' => $catalogError->getMessage(),
                ]);
            }

            return [
                'selected_products' => $selectedProducts,
                'default_product_selections' => $selectedProducts,
                'default_commission_rate' => (float) config('comet.store.default_commission_rate', 15),
                'max_featured_products' => $maxFeatured,
                'max_default_product_selections' => $maxFeatured,
                'checkout_mode' => $this->resolveCheckoutModeForAffiliate($professionalId),
            ];
        }
    }

    private function resolveCheckoutModeForAffiliate(string $affiliateProfessionalId): string
    {
        if ($affiliateProfessionalId === '') {
            return 'shopify';
        }

        try {
            $brandProfessionalId = DB::table('core.brand_partner_links')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->orderByDesc('is_primary')
                ->orderByDesc('created_at')
                ->value('brand_professional_id');

            $brandProfessionalId = trim((string) $brandProfessionalId);
            if ($brandProfessionalId === '') {
                return 'shopify';
            }

            $mode = DB::table('retail.brand_store_settings')
                ->where('professional_id', $brandProfessionalId)
                ->value('checkout_mode');

            $mode = strtolower(trim((string) $mode));

            return in_array($mode, ['shopify', 'stripe'], true) ? $mode : 'shopify';
        } catch (Throwable) {
            return 'shopify';
        }
    }

    private function shouldRememberConfirmationPreference(Request $request): bool
    {
        return $request->boolean('remember_confirmation_preference')
            || $request->boolean('always_allow_confirmation')
            || $request->boolean('dont_ask_again');
    }
}
