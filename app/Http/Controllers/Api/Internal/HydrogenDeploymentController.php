<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\BrandStoreSettings;
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
     * Return brands with a stored Oxygen deployment token.
     *
     * When called from the GitHub Actions workflow_dispatch trigger for a
     * single-brand deploy, the optional ?professional_id= query parameter
     * filters to just that brand. Without it, all brands are returned for
     * the standard push-triggered deploy-everyone run.
     *
     * @return JsonResponse Array of {shop_domain, oxygen_deployment_token, oxygen_storefront_id}
     */
    public function targets(Request $request): JsonResponse
    {
        $query = BrandStoreSettings::query()
            ->whereNotNull('oxygen_deployment_token');

        // Single-brand deploy: the workflow_dispatch trigger passes a
        // professional_id to filter to just the brand that saved credentials.
        if ($professionalId = $request->query('professional_id')) {
            $query->where('professional_id', $professionalId);
        }

        $settings = $query->get(['professional_id', 'oxygen_deployment_token', 'oxygen_storefront_id']);

        $targets = $settings->map(function (BrandStoreSettings $row) {
            // Resolve the shop domain from the brand's Shopify integration
            $integration = ProfessionalIntegration::query()
                ->where('professional_id', $row->professional_id)
                ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->value('shopify_shop_domain');

            return [
                'shop_domain' => $integration,
                // Decrypted by the encrypted cast — never stored in plain text
                'oxygen_deployment_token' => $row->oxygen_deployment_token,
                'oxygen_storefront_id' => $row->oxygen_storefront_id,
            ];
        })->values();

        return $this->success($targets);
    }
}
