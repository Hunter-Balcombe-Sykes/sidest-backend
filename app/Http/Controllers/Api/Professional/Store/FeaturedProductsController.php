<?php

// ------ TOBIAS ADDITIONS TO REVIEW --------

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FeaturedProductsController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    private const MAX_FEATURED = 10;

    /**
     * GET /store/featured-products
     * Returns the array of selected Shopify product GIDs.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'selected_products' => $settings['selected_products'] ?? [],
        ]);
    }

    /**
     * PUT /store/featured-products
     * Replaces the full list of selected Shopify product GIDs (max 10).
     */
    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);

        $validator = Validator::make($request->all(), [
            'selected_products'   => ['required', 'array', 'max:' . self::MAX_FEATURED],
            'selected_products.*' => ['string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        $existing = is_array($site->settings) ? $site->settings : [];
        $existing['selected_products'] = array_values($validated['selected_products']);

        $site->settings = $existing;
        $site->save();

        return $this->success([
            'selected_products' => $existing['selected_products'],
        ]);
    }
}

// ------ END TOBIAS ADDITIONS --------
