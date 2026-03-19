<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
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
     * Returns the brand's default commission rate.
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

        return $this->success([
            'default_commission_rate' => $defaultCommission,
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
        if (! $this->isBrandProfessionalType($professional->professional_type)) {
            return $this->error('Only brand accounts can manage brand store settings.', 403);
        }

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

    private function isBrandProfessionalType(mixed $value): bool
    {
        return mb_strtolower(trim((string) $value)) === 'brand';
    }
}
