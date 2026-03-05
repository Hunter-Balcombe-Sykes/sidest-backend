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

    /**
     * GET /store/featured-products
     * Returns selected products with their commission rates and the default rate.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $settings = is_array($site->settings) ? $site->settings : [];

        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $maxFeatured = (int) config('comet.store.max_featured_products', 10);

        return $this->success([
            'selected_products'       => $settings['selected_products'] ?? [],
            'default_commission_rate' => $defaultRate,
            'max_featured_products'   => $maxFeatured,
        ]);
    }

    /**
     * PUT /store/featured-products
     * Replaces the full list of selected products (max from config).
     * Each entry can be a plain string (product GID) or an object { id, commission }.
     */
    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);

        $maxFeatured = (int) config('comet.store.max_featured_products', 10);

        $validator = Validator::make($request->all(), [
            'selected_products'              => ['required', 'array', 'max:' . $maxFeatured],
            'selected_products.*.id'         => ['required', 'string', 'max:255'],
            'selected_products.*.commission' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
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
            'selected_products'       => $existing['selected_products'],
            'default_commission_rate' => (float) config('comet.store.default_commission_rate', 15),
            'max_featured_products'   => $maxFeatured,
        ]);
    }
}

// ------ END TOBIAS ADDITIONS --------
