<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Retail\BrandProductSetting;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class BrandStoreController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * GET /store/brand-settings
     * Returns the brand's default commission rate and all per-product settings.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $storeSettings = BrandStoreSettings::where('professional_id', $professional->id)->first();
        $defaultCommission = $storeSettings ? (float) $storeSettings->default_commission_rate : 15.0;

        $products = BrandProductSetting::where('professional_id', $professional->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($p) => [
                'shopify_product_id' => (string) $p->shopify_product_id,
                'commission_override' => $p->commission_override !== null ? (float) $p->commission_override : null,
                'discount_rate'       => $p->discount_rate !== null ? (float) $p->discount_rate : null,
                'is_featured'         => (bool) $p->is_featured,
                'sort_order'          => (int) $p->sort_order,
            ])
            ->values()
            ->all();

        return $this->success([
            'default_commission_rate' => $defaultCommission,
            'products'                => $products,
        ]);
    }

    /**
     * PATCH /store/brand-settings
     * Updates the brand's default commission rate.
     * Accepts: { default_commission_rate: number }
     */
    public function updateSettings(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $validator = Validator::make($request->all(), [
            'default_commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $rate = (float) $validator->validated()['default_commission_rate'];

        BrandStoreSettings::updateOrCreate(
            ['professional_id' => (string) $professional->id],
            ['default_commission_rate' => $rate]
        );

        return $this->success(['default_commission_rate' => $rate]);
    }

    /**
     * PUT /store/brand-product-settings
     * Full replace of all per-product settings for this brand.
     * Accepts: { products: [{ shopify_product_id, commission_override?, discount_rate?, is_featured?, sort_order? }] }
     *
     * Rules:
     *  - commission_override must be >= brand default_commission_rate (if set)
     *  - max 10 products may have is_featured = true
     */
    public function updateProductSettings(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $validator = Validator::make($request->all(), [
            'products'                        => ['required', 'array'],
            'products.*.shopify_product_id'   => ['required', 'string', 'max:255'],
            'products.*.commission_override'  => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'products.*.discount_rate'        => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'products.*.is_featured'          => ['sometimes', 'boolean'],
            'products.*.sort_order'           => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        // Load default commission for override floor validation
        $storeSettings = BrandStoreSettings::where('professional_id', $professional->id)->first();
        $defaultCommission = $storeSettings ? (float) $storeSettings->default_commission_rate : 0.0;

        foreach ($validated['products'] as $product) {
            if (isset($product['commission_override']) && $product['commission_override'] !== null) {
                $override = (float) $product['commission_override'];
                if ($override < $defaultCommission) {
                    return $this->error(
                        "Commission override ({$override}%) cannot be below the brand default commission rate ({$defaultCommission}%).",
                        422
                    );
                }
            }
        }

        // Validate max 10 featured
        $featuredCount = collect($validated['products'])
            ->filter(fn ($p) => ! empty($p['is_featured']))
            ->count();

        if ($featuredCount > 10) {
            return $this->error('A maximum of 10 products can be marked as featured.', 422);
        }

        try {
            DB::transaction(function () use ($professional, $validated) {
                BrandProductSetting::where('professional_id', $professional->id)->delete();

                foreach ($validated['products'] as $index => $product) {
                    BrandProductSetting::create([
                        'professional_id'    => (string) $professional->id,
                        'shopify_product_id' => (string) $product['shopify_product_id'],
                        'commission_override' => $product['commission_override'] ?? null,
                        'discount_rate'       => $product['discount_rate'] ?? null,
                        'is_featured'         => (bool) ($product['is_featured'] ?? false),
                        'sort_order'          => $product['sort_order'] ?? $index,
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Brand product settings update failed.', [
                'professional_id' => (string) $professional->id,
                'error'           => $e->getMessage(),
            ]);

            return $this->error('Failed to save product settings.', 500);
        }

        return $this->index($request);
    }
}
