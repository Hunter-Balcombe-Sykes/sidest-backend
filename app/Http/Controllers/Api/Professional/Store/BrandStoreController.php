<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Store\BrandAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrandStoreController extends ApiController
{
    use ResolveCurrentProfessional;

    private const DEFAULT_COMMISSION_RATE = 15.0;
    private const DEFAULT_CHECKOUT_MODE = 'shopify';

    public function __construct(
        private readonly BrandAccessService $brandAccess,
    ) {}

    /**
     * GET /store/brand-settings
     * Returns the brand's default commission rate and favourite product IDs.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$brandProfessionalId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null
        );

        if ($error !== null) {
            return $error;
        }

        return $this->success($this->storeSettingsPayloadForBrand($brandProfessionalId));
    }

    /**
     * PATCH /store/brand-settings
     * Updates the brand's default commission rate and/or favourites.
     * Accepts:
     *   {
     *      brand_professional_id?: uuid,
     *      default_commission_rate?: number,
     *      checkout_mode?: "shopify"|"stripe",
     *      favourite_brand_product_ids?: uuid[]
     *   }
     */
    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'checkout_mode' => ['sometimes', 'string', 'in:shopify,stripe'],
            'favourite_brand_product_ids' => ['sometimes', 'array', 'max:10'],
            'favourite_brand_product_ids.*' => ['uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        [$brandProfessionalId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validated['brand_professional_id']) ? (string) $validated['brand_professional_id'] : null
        );

        if ($error !== null) {
            return $error;
        }

        if (
            ! array_key_exists('default_commission_rate', $validated)
            && ! array_key_exists('checkout_mode', $validated)
            && ! array_key_exists('favourite_brand_product_ids', $validated)
        ) {
            return $this->error('Provide default_commission_rate, checkout_mode, and/or favourite_brand_product_ids.', 422);
        }

        $attributes = [];
        if (array_key_exists('default_commission_rate', $validated)) {
            $attributes['default_commission_rate'] = (float) $validated['default_commission_rate'];
        }

        if (array_key_exists('checkout_mode', $validated)) {
            $attributes['checkout_mode'] = (string) $validated['checkout_mode'];
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
                    ->where('brand_professional_id', $brandProfessionalId)
                    ->whereIn('id', $favouriteIds)
                    ->count();

                if ($allowedCount !== count($favouriteIds)) {
                    return $this->error('One or more favourite brand_product_id values are invalid for this brand.', 422);
                }
            }

            $attributes['favourite_brand_product_ids'] = $favouriteIds;
        }

        BrandStoreSettings::updateOrCreate(
            ['professional_id' => $brandProfessionalId],
            $attributes
        );

        return $this->success($this->storeSettingsPayloadForBrand($brandProfessionalId));
    }

    /**
     * @return array{
     *   default_commission_rate: float,
     *   checkout_mode: string,
     *   favourite_brand_product_ids: array<int, string>,
     *   brand_professional_id: string
     * }
     */
    private function storeSettingsPayloadForBrand(string $brandProfessionalId): array
    {
        $storeSettings = BrandStoreSettings::where('professional_id', $brandProfessionalId)->first();
        $defaultCommission = $storeSettings
            ? (float) $storeSettings->default_commission_rate
            : self::DEFAULT_COMMISSION_RATE;
        $checkoutMode = trim((string) ($storeSettings?->checkout_mode ?? self::DEFAULT_CHECKOUT_MODE));
        if (! in_array($checkoutMode, ['shopify', 'stripe'], true)) {
            $checkoutMode = self::DEFAULT_CHECKOUT_MODE;
        }
        $favouriteBrandProductIds = collect($storeSettings?->favourite_brand_product_ids ?? [])
            ->map(static fn ($value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'default_commission_rate' => $defaultCommission,
            'checkout_mode' => $checkoutMode,
            'favourite_brand_product_ids' => $favouriteBrandProductIds,
            'brand_professional_id' => $brandProfessionalId,
        ];
    }

    /**
     * @return array{0: string, 1: JsonResponse|null}
     */
    private function resolveTargetBrandProfessionalId(Request $request, ?string $requestedBrandProfessionalId): array
    {
        $professional = $this->currentProfessional($request);
        $requestedBrandProfessionalId = trim((string) $requestedBrandProfessionalId);

        if ($requestedBrandProfessionalId === '') {
            if ($this->brandAccess->isBrandProfessional($professional)) {
                $requestedBrandProfessionalId = (string) $professional->id;
            } else {
                return ['', $this->error('brand_professional_id is required for this account type.', 422)];
            }
        }

        if (! $this->brandAccess->canManageBrand($professional, $requestedBrandProfessionalId)) {
            return ['', $this->error('You are not permitted to manage brand store settings for this brand.', 403)];
        }

        return [$requestedBrandProfessionalId, null];
    }
}
