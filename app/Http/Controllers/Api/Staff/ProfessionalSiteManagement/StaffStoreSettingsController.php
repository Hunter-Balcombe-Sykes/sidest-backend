<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Core\Professional\Professional;
use App\Services\Hydrogen\HydrogenDeploymentService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Staff admin overrides brand commission rate and payout hold days. DB-only write —
// deliberately skips Shopify metafield sync to avoid API calls during support operations.
// The `deploy` action is the explicit opt-in to push those DB-only edits to Shopify.
class StaffStoreSettingsController extends ApiController
{
    public function __construct(
        private readonly BrandCatalogService $catalogService,
        private readonly HydrogenDeploymentService $deployment,
    ) {}

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

    /**
     * POST /api/staff/professionals/{professional}/store-settings/deploy
     *
     * Push the brand's current DB commission rate to its Shopify metafield so the
     * Hydrogen storefront sees the staff edit. Optionally also dispatches a Hydrogen
     * rebuild if the brand has an Oxygen deployment token configured.
     *
     * Logged as `staff-deploy` (placeholder for #OPS-2 audit log).
     */
    public function deploy(Request $request, Professional $professional): JsonResponse
    {
        $settings = BrandStoreSettings::query()
            ->where('professional_id', $professional->id)
            ->first();

        if (! $settings) {
            return $this->error('No store settings to deploy. Edit the commission rate first.', 422);
        }

        try {
            $resolved = $this->catalogService->resolveBrandIntegration($professional);
            $integration = $resolved['integration'];

            $result = $this->catalogService->setShopMetafields($integration, [
                [
                    'key' => 'default_commission_rate',
                    'value' => (string) $settings->default_commission_rate,
                    'type' => 'number_decimal',
                ],
            ]);

            if (! $result['success']) {
                $msg = $result['userErrors'][0]['message'] ?? 'Failed to push settings to Shopify.';

                return $this->error($msg, 422);
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        // Trigger an Oxygen/Hydrogen rebuild if configured — picks up the new metafield
        // values immediately rather than waiting for the next deploy.
        $hydrogenDispatched = false;
        if (! empty($settings->oxygen_deployment_token)) {
            $this->deployment->dispatchDeployment($professional->id);
            $hydrogenDispatched = true;
        }

        Log::info('staff-deploy: brand store settings pushed to Shopify', [
            'action' => 'staff-deploy',
            'professional_id' => $professional->id,
            'default_commission_rate' => (float) $settings->default_commission_rate,
            'hydrogen_dispatched' => $hydrogenDispatched,
        ]);

        return $this->success([
            'deployed' => true,
            'default_commission_rate' => (float) $settings->default_commission_rate,
            'hydrogen_rebuild_triggered' => $hydrogenDispatched,
        ]);
    }
}
