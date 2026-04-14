<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Internal endpoint called by the GitHub Actions deployment workflow.
// Returns all brands that have an Oxygen deployment token stored, so the workflow
// can build the Hydrogen bundle once and deploy it to every brand's Oxygen instance.
// The token is decrypted by the model's encrypted cast before being returned here —
// it is transmitted over HTTPS and only to the CI runner.
class HydrogenDeploymentController extends ApiController
{
    /**
     * Return all brands with a stored Oxygen deployment token.
     *
     * @return JsonResponse Array of {shop_domain, oxygen_deployment_token, oxygen_storefront_id}
     */
    public function targets(Request $request): JsonResponse
    {
        $settings = BrandStoreSettings::query()
            ->whereNotNull('oxygen_deployment_token')
            ->get(['professional_id', 'oxygen_deployment_token', 'oxygen_storefront_id']);

        $targets = $settings->map(function (BrandStoreSettings $row) {
            // Resolve the shop domain from the brand's Shopify integration
            $integration = ProfessionalIntegration::query()
                ->where('professional_id', $row->professional_id)
                ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->value('shopify_shop_domain');

            return [
                'shop_domain'              => $integration,
                // Decrypted by the encrypted cast — never stored in plain text
                'oxygen_deployment_token'  => $row->oxygen_deployment_token,
                'oxygen_storefront_id'     => $row->oxygen_storefront_id,
            ];
        })->values();

        return $this->success($targets);
    }
}
