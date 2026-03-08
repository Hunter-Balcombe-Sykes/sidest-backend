<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Retail\ProfessionalSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FeaturedProductsController extends ApiController
{
    use ResolveCurrentProfessional;

    private const MAX_FEATURED = 6;

    /**
     * GET /store/featured-products
     * Returns the professional's selected Shopify product IDs.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $selections = ProfessionalSelection::where('professional_id', $professional->id)
            ->orderBy('sort_order')
            ->get(['id', 'shopify_product_id', 'sort_order']);

        return $this->success([
            'selected_products'     => $selections,
            'max_featured_products' => self::MAX_FEATURED,
        ]);
    }

    /**
     * PUT /store/featured-products
     * Replaces the full list of selected Shopify product IDs (max 6).
     * Expects: { products: [ { shopify_product_id, sort_order? }, ... ] }
     */
    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $validator = Validator::make($request->all(), [
            'products'                       => ['required', 'array', 'max:' . self::MAX_FEATURED],
            'products.*.shopify_product_id'  => ['required', 'string', 'max:255'],
            'products.*.sort_order'          => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        DB::transaction(function () use ($professional, $validated) {
            // Remove all current selections and replace
            ProfessionalSelection::where('professional_id', $professional->id)->delete();

            foreach ($validated['products'] as $index => $product) {
                ProfessionalSelection::create([
                    'professional_id'  => $professional->id,
                    'shopify_product_id' => $product['shopify_product_id'],
                    'sort_order'       => $product['sort_order'] ?? $index,
                ]);
            }
        });

        $selections = ProfessionalSelection::where('professional_id', $professional->id)
            ->orderBy('sort_order')
            ->get(['id', 'shopify_product_id', 'sort_order']);

        return $this->success([
            'selected_products'     => $selections,
            'max_featured_products' => self::MAX_FEATURED,
        ]);
    }
}
