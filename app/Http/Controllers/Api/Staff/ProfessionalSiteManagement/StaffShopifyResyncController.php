<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyDataResyncService;
use App\Services\Shopify\ShopifyDisconnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

// V2: Staff admin Shopify integration actions — resync, disconnect, re-register webhooks.
// Each method mirrors a brand-facing action so support can act on a brand's behalf when
// the brand can't (or won't) click the button themselves. All writes are admin-gated at
// the route level.
class StaffShopifyResyncController extends ApiController
{
    public function __construct(
        private readonly ShopifyDataResyncService $resyncService,
        private readonly ShopifyDisconnectService $disconnectService,
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
            return $this->error('Unable to resync from Shopify: '.$e->getMessage(), 502);
        }

        return $this->success($result);
    }

    /**
     * POST /api/staff/professionals/{professional}/integrations/shopify/disconnect
     *
     * Severs a stale Shopify connection on a brand's behalf. Runs the same teardown
     * sweep + local cleanup the brand-side disconnect performs, via the shared
     * ShopifyDisconnectService. Returns 404 if no integration row exists so support
     * doesn't get a misleading "success" on an already-clean brand.
     */
    public function disconnect(Request $request, Professional $professional): JsonResponse
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('No Shopify integration found for this professional.', 404);
        }

        $staff = $request->attributes->get('partna_staff');
        $actorContext = [
            'actor_staff_id' => $staff ? (string) $staff->id : null,
        ];

        $result = $this->disconnectService->disconnect((string) $professional->id, $actorContext);

        return $this->success([
            'connected' => false,
            'brand_professional_id' => (string) $professional->id,
            'teardown' => $result['teardown'],
            'selections_deleted' => $result['selections_deleted'],
        ]);
    }

    /**
     * POST /api/staff/professionals/{professional}/integrations/shopify/register-webhooks
     *
     * Re-arms order webhooks for a brand whose Shopify webhook subscription has
     * drifted (topic-version bump, scope re-grant, manual deletion in the Shopify
     * admin). Dispatches the same job the brand-facing endpoint dispatches.
     */
    public function registerWebhooks(Request $request, Professional $professional): JsonResponse
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration || empty($integration->access_token)) {
            return $this->error('No Shopify integration found for this professional.', 404);
        }

        $staff = $request->attributes->get('partna_staff');

        RegisterShopifyWebhooksJob::dispatch((string) $integration->id);

        Log::info('Shopify webhook re-register dispatched by staff', [
            'actor_staff_id' => $staff ? (string) $staff->id : null,
            'brand_professional_id' => (string) $professional->id,
            'integration_id' => (string) $integration->id,
        ]);

        return $this->success([
            'queued' => true,
            'integration_id' => (string) $integration->id,
            'brand_professional_id' => (string) $professional->id,
        ]);
    }
}
