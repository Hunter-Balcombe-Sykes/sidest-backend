<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Retail\ProfessionalSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FeaturedProductsController extends ApiController
{
    use ResolveCurrentProfessional;

    private ?bool $commissionOverrideSupported = null;

    /**
     * GET /store/featured-products
     * Returns the professional's selected Shopify product IDs with commission overrides.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $maxFeatured = (int) config('comet.store.max_featured_products', 10);
        $supportsCommissionOverride = $this->supportsCommissionOverride();

        $columns = ['id', 'shopify_product_id', 'sort_order'];
        if ($supportsCommissionOverride) {
            $columns[] = 'commission_override';
        }

        $selections = ProfessionalSelection::where('professional_id', $professional->id)
            ->orderBy('sort_order')
            ->get($columns);

        return $this->success([
            'selected_products'       => $this->toSelectionResponse($selections, $supportsCommissionOverride),
            'default_commission_rate' => $defaultRate,
            'max_featured_products'   => $maxFeatured,
        ]);
    }

    /**
     * PUT /store/featured-products
     * Replaces the full list of selected Shopify product IDs with optional commission overrides (max 10).
     * Expects: { products: [ { shopify_product_id, sort_order?, commission_override? }, ... ] }
     */
    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $maxFeatured = (int) config('comet.store.max_featured_products', 10);
        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $supportsCommissionOverride = $this->supportsCommissionOverride();

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

        DB::transaction(function () use ($professional, $validated) {
            // Remove all current selections and replace
            ProfessionalSelection::where('professional_id', $professional->id)->delete();

            foreach ($validated['products'] as $index => $product) {
                $attributes = [
                    'professional_id'      => $professional->id,
                    'shopify_product_id'   => $product['shopify_product_id'],
                    'sort_order'           => $product['sort_order'] ?? $index,
                ];
                if ($this->supportsCommissionOverride()) {
                    $attributes['commission_override'] = $product['commission_override'] ?? null;
                }

                ProfessionalSelection::create($attributes);
            }
        });

        $columns = ['id', 'shopify_product_id', 'sort_order'];
        if ($supportsCommissionOverride) {
            $columns[] = 'commission_override';
        }

        $selections = ProfessionalSelection::where('professional_id', $professional->id)
            ->orderBy('sort_order')
            ->get($columns);

        return $this->success([
            'selected_products'       => $this->toSelectionResponse($selections, $supportsCommissionOverride),
            'default_commission_rate' => $defaultRate,
            'max_featured_products'   => $maxFeatured,
        ]);
    }

    private function supportsCommissionOverride(): bool
    {
        if ($this->commissionOverrideSupported !== null) {
            return $this->commissionOverrideSupported;
        }

        $this->commissionOverrideSupported = DB::table('information_schema.columns')
            ->where('table_schema', 'retail')
            ->where('table_name', 'professional_selections')
            ->where('column_name', 'commission_override')
            ->exists();

        return $this->commissionOverrideSupported;
    }

    private function toSelectionResponse(Collection $rows, bool $supportsCommissionOverride): Collection
    {
        return $rows->map(function ($row) use ($supportsCommissionOverride) {
            return [
                'id' => $row->id,
                'shopify_product_id' => $row->shopify_product_id,
                'sort_order' => $row->sort_order,
                'commission_override' => $supportsCommissionOverride ? $row->commission_override : null,
            ];
        })->values();
    }
}
