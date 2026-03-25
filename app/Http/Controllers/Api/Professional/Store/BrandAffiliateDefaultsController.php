<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Site\Theme;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrandAffiliateDefaultsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function show(Request $request)
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $settings = BrandStoreSettings::where('professional_id', $professional->id)->first();

        return $this->success([
            'default_affiliate_theme_id'    => $settings?->default_affiliate_theme_id,
            'default_affiliate_product_ids' => $settings?->default_affiliate_product_ids ?? [],
        ]);
    }

    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $validator = Validator::make($request->all(), [
            'default_affiliate_theme_id'    => ['sometimes', 'nullable', 'uuid'],
            'default_affiliate_product_ids' => ['sometimes', 'array', 'max:10'],
            'default_affiliate_product_ids.*' => ['uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (
            ! array_key_exists('default_affiliate_theme_id', $validated)
            && ! array_key_exists('default_affiliate_product_ids', $validated)
        ) {
            return $this->error('Provide default_affiliate_theme_id and/or default_affiliate_product_ids.', 422);
        }

        $attributes = [];

        // Validate theme exists
        if (array_key_exists('default_affiliate_theme_id', $validated)) {
            $themeId = $validated['default_affiliate_theme_id'];
            if ($themeId !== null && ! Theme::where('id', $themeId)->exists()) {
                return $this->error('The selected theme does not exist.', 422);
            }
            $attributes['default_affiliate_theme_id'] = $themeId;
        }

        // Validate products belong to this brand
        if (array_key_exists('default_affiliate_product_ids', $validated)) {
            $productIds = collect($validated['default_affiliate_product_ids'] ?? [])
                ->map(static fn ($v): string => trim((string) $v))
                ->filter(static fn (string $v): bool => $v !== '')
                ->unique()
                ->values()
                ->all();

            if ($productIds !== []) {
                $validCount = BrandProduct::query()
                    ->where('brand_professional_id', $professional->id)
                    ->whereIn('id', $productIds)
                    ->count();

                if ($validCount !== count($productIds)) {
                    return $this->error('One or more product IDs are invalid for this brand.', 422);
                }
            }

            $attributes['default_affiliate_product_ids'] = $productIds;
        }

        BrandStoreSettings::updateOrCreate(
            ['professional_id' => $professional->id],
            $attributes
        );

        $settings = BrandStoreSettings::where('professional_id', $professional->id)->first();

        return $this->success([
            'default_affiliate_theme_id'    => $settings?->default_affiliate_theme_id,
            'default_affiliate_product_ids' => $settings?->default_affiliate_product_ids ?? [],
        ]);
    }
}
