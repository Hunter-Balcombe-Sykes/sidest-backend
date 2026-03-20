<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrandStoreController extends ApiController
{
    use ResolveCurrentProfessional;

    private const DEFAULT_COMMISSION_RATE = 15.0;

    /**
     * GET /store/brand-settings
     * Returns the brand's default commission rate and favourite product IDs.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);
        if (! $this->isBrandProfessionalType($professional->professional_type)) {
            return $this->error('Only brand accounts can manage brand store settings.', 403);
        }

        $storeSettings = BrandStoreSettings::where('professional_id', $professional->id)->first();
        $defaultCommission = $storeSettings
            ? (float) $storeSettings->default_commission_rate
            : self::DEFAULT_COMMISSION_RATE;
        $favouriteBrandProductIds = collect($storeSettings?->favourite_brand_product_ids ?? [])
            ->map(static fn ($value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        return $this->success([
            'default_commission_rate' => $defaultCommission,
            'favourite_brand_product_ids' => $favouriteBrandProductIds,
        ]);
    }

    /**
     * PATCH /store/brand-settings
     * Updates the brand's default commission rate and/or favourites.
     * Accepts:
     *   { default_commission_rate?: number, favourite_brand_product_ids?: uuid[] }
     */
    public function updateSettings(Request $request)
    {
        $professional = $this->currentProfessional($request);
        if (! $this->isBrandProfessionalType($professional->professional_type)) {
            return $this->error('Only brand accounts can manage brand store settings.', 403);
        }

        $validator = Validator::make($request->all(), [
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'favourite_brand_product_ids' => ['sometimes', 'array', 'max:10'],
            'favourite_brand_product_ids.*' => ['uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        if (! array_key_exists('default_commission_rate', $validated) && ! array_key_exists('favourite_brand_product_ids', $validated)) {
            return $this->error('Provide default_commission_rate and/or favourite_brand_product_ids.', 422);
        }

        $attributes = [];
        if (array_key_exists('default_commission_rate', $validated)) {
            $attributes['default_commission_rate'] = (float) $validated['default_commission_rate'];
        }

        if (array_key_exists('favourite_brand_product_ids', $validated)) {
            $favouriteIds = collect($validated['favourite_brand_product_ids'] ?? [])
                ->map(static fn ($value): string => trim((string) $value))
                ->filter(static fn (string $value): bool => $value !== '')
                ->unique()
                ->values()
                ->all();

            if ($favouriteIds !== []) {
                $allowedCount = BrandProduct::query()
                    ->where('brand_professional_id', (string) $professional->id)
                    ->whereIn('id', $favouriteIds)
                    ->count();

                if ($allowedCount !== count($favouriteIds)) {
                    return $this->error('One or more favourite brand_product_id values are invalid for this brand.', 422);
                }
            }

            $attributes['favourite_brand_product_ids'] = $favouriteIds;
        }

        BrandStoreSettings::updateOrCreate(
            ['professional_id' => (string) $professional->id],
            $attributes
        );

        return $this->index($request);
    }

    private function isBrandProfessionalType(mixed $value): bool
    {
        return mb_strtolower(trim((string) $value)) === 'brand';
    }
}
