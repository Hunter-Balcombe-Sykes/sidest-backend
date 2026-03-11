<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Models\Retail\ProfessionalSelection;
use App\Services\Cache\SiteCacheService;
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
        private readonly FeaturedProductsPayloadService $featuredProductsPayloads
    ) {}

    /**
     * GET /store/featured-products
     * Returns the professional's selected Shopify product IDs with commission overrides.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);

        return $this->success(
            $this->featuredProductsPayloads->build(
                (string) $professional->id,
                $site->settings,
                'professional_store_index'
            )
        );
    }

    /**
     * PUT /store/featured-products
     * Replaces the full list of selected Shopify product IDs with optional commission overrides (max 10).
     * Expects: { products: [ { shopify_product_id, sort_order?, commission_override? }, ... ] }
     */
    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $maxFeatured = (int) config('comet.store.max_featured_products', 10);
        $supportsCommissionOverride = $this->featuredProductsPayloads->supportsCommissionOverride()
            && $this->featuredProductsPayloads->hasSelectionsTable();

        $validator = Validator::make($request->all(), [
            'products'                            => ['required', 'array', 'max:' . $maxFeatured],
            'products.*.shopify_product_id'       => ['required', 'string', 'max:255'],
            'products.*.sort_order'               => ['sometimes', 'integer', 'min:0'],
            'products.*.commission_override'      => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
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
                'Featured products table is unavailable. Run retail schema migrations and try again.',
                503
            );
        }

        try {
            DB::transaction(function () use ($professional, $validated, $supportsCommissionOverride) {
                // Remove all current selections and replace
                ProfessionalSelection::where('professional_id', $professional->id)->delete();

                foreach ($validated['products'] as $index => $product) {
                    $attributes = [
                        'professional_id'      => $professional->id,
                        'shopify_product_id'   => $product['shopify_product_id'],
                        'sort_order'           => $product['sort_order'] ?? $index,
                    ];
                    if ($supportsCommissionOverride) {
                        $attributes['commission_override'] = $product['commission_override'] ?? null;
                    }

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
            $message = 'Failed to save featured products to retail.professional_selections.';

            if ($e instanceof QueryException) {
                $sqlState = (string) $e->getCode();
                if ($sqlState === '23505') {
                    $status = 422;
                    $message = 'Duplicate featured products are not allowed.';
                } elseif ($sqlState === '23514') {
                    $status = 422;
                    $message = 'Too many featured products selected.';
                }
            }

            if (config('app.debug')) {
                $message .= ' ' . $e->getMessage();
            }

            return $this->error($message, $status);
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

        return $this->success(
            $this->featuredProductsPayloads->build(
                (string) $professional->id,
                $site->settings,
                'professional_store_update'
            )
        );
    }
}
