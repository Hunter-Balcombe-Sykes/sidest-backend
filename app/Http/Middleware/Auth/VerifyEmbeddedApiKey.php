<?php

namespace App\Http\Middleware\Auth;

use App\Services\Shopify\ShopifyShopResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Verifies requests from the Sidest-Embedded Shopify app.
// Expects Authorization: Bearer {SIDEST_EMBEDDED_API_KEY} + X-Shopify-Shop: {shop_domain}.
// Resolves the brand professional from the shop domain and attaches it as
// request attribute 'embedded_professional_id' for downstream controllers.
class VerifyEmbeddedApiKey
{
    public function __construct(
        private readonly ShopifyShopResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.embedded.api_key');

        if ($expected === '') {
            // Dev/test bypass: missing config is acceptable when running locally
            // or under the test suite. Anywhere else (production, staging, any
            // unrecognized env) we fail closed — without this gate, an empty
            // SIDEST_EMBEDDED_API_KEY on a production deploy would silently open every
            // /internal/embedded/* route, including the deployment-token endpoint
            // that can rewrite a brand's storefront, to anonymous traffic.
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }

            throw new \RuntimeException(
                'services.embedded.api_key is not configured — refusing to fall through to bypass outside local/testing.'
            );
        }

        $provided = (string) str_replace('Bearer ', '', $request->header('Authorization', ''));

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid or missing embedded API key.'], 403);
        }

        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop', '')));

        if ($shopDomain === '') {
            return response()->json(['message' => 'Missing X-Shopify-Shop header.'], 400);
        }

        $professionalId = $this->resolver->resolveProfessionalId($shopDomain);

        if ($professionalId === null) {
            // Distinct error code so the embedded app can show the connect-account screen
            // rather than a generic error.
            return response()->json([
                'error' => 'shop_not_connected',
                'message' => 'No Partna account is linked to this Shopify store.',
            ], 404);
        }

        // Attach for controller use — avoids re-querying the integration table
        $request->attributes->set('embedded_professional_id', $professionalId);

        return $next($request);
    }
}
