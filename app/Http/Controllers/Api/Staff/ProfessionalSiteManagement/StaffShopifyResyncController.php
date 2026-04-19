<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyDataResyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

// V2: Staff admin triggers a Shopify data resync for any brand. Uses the same service
// and rate-limit key as the brand-facing endpoint — 1 resync per integration per 60s.
class StaffShopifyResyncController extends ApiController
{
    public function __construct(
        private readonly ShopifyDataResyncService $resyncService,
    ) {}

    /**
     * POST /api/staff/professionals/{professional}/integrations/shopify/resync
     */
    public function invoke(Request $request, Professional $professional): JsonResponse
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('No Shopify integration found for this professional.', 404);
        }

        $rateLimitKey = "shopify-resync:{$integration->id}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->error('Shopify resync is rate-limited. Try again shortly.', 429)
                ->header('Retry-After', (string) $retryAfter);
        }
        RateLimiter::hit($rateLimitKey, 60);

        try {
            $result = $this->resyncService->resync($integration);
        } catch (RuntimeException $e) {
            return $this->error('Unable to resync from Shopify: ' . $e->getMessage(), 502);
        }

        return $this->success($result);
    }
}
