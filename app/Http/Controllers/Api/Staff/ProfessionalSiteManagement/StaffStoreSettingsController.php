<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff admin overrides brand commission rate and payout hold days. DB-only write —
// deliberately skips Shopify metafield sync to avoid API calls during support operations.
class StaffStoreSettingsController extends ApiController
{
    /**
     * PATCH /api/staff/professionals/{professional}/store-settings
     *
     * Updatable: default_commission_rate (0–100), payout_hold_days (0/7/14/28).
     */
    public function update(Request $request, Professional $professional): JsonResponse
    {
        $data = $request->validate([
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payout_hold_days' => ['sometimes', 'integer', 'in:0,7,14,28'],
        ]);

        if (empty($data)) {
            return $this->error('No updatable fields provided.', 422);
        }

        $settings = BrandStoreSettings::updateOrCreate(
            ['professional_id' => $professional->id],
            $data
        );

        return $this->success([
            'default_commission_rate' => (float) $settings->default_commission_rate,
            'payout_hold_days' => $settings->payout_hold_days,
        ]);
    }
}
