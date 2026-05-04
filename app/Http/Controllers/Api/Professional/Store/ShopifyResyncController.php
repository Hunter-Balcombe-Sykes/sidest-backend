<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Resources\ShopifyResyncResource;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyDataResyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

// V2: Brand "Resync from Shopify" trigger. Refreshes profile/brand fields + logo + theme tokens.
//     Rate-limited to once per 60s per integration so users cannot flood Shopify's API.
class ShopifyResyncController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly ShopifyDataResyncService $resyncService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        // Scope strictly by the authenticated professional — never accept an integration id
        // from the request body; that would let a brand touch another brand's integration.
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $pro->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('No Shopify integration found for this brand.', 404);
        }

        if (trim((string) $integration->access_token) === '') {
            return $this->error('Your Shopify store is not fully connected.', 409);
        }

        $rateLimitKey = "shopify-resync:{$integration->id}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->error(
                'Shopify resync is rate-limited. Try again shortly.',
                429,
            )->header('Retry-After', (string) $retryAfter);
        }
        RateLimiter::hit($rateLimitKey, 60);

        try {
            $result = $this->resyncService->resync($integration);
        } catch (RuntimeException $e) {
            return $this->error('Unable to resync from Shopify: '.$e->getMessage(), 502);
        }

        // Resolve the resource to a plain array so the response is un-wrapped (no "data" envelope).
        return $this->success((new ShopifyResyncResource($result))->resolve($request));
    }
}
