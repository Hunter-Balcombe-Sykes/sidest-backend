<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Connect a Shopify shop to an existing Partna brand account.
//
// Called from the embedded setup wizard step 1 — the shop has just installed
// the Partna app but is NOT yet linked to a professional. The route uses
// `shopify.session:lenient`: VerifyShopifySessionToken validates the JWT
// signature + claims but SKIPS the shop-resolution step (since the whole
// point of this endpoint is to perform that linking).
//
// shop_domain comes from the JWT's `dest` claim (cryptographically bound to
// the App Bridge token), stashed on the request as `embedded_shop_domain`.
// professional_id comes from the connection code the brand generated in the
// Partna dashboard (Redis-stored with 30-min TTL).
class EmbeddedConnectController extends ApiController
{
    /**
     * Connect a Shopify shop to a Partna account via a time-limited connection code.
     */
    public function connect(Request $request): JsonResponse
    {
        $shopDomain = (string) $request->attributes->get('embedded_shop_domain');
        if ($shopDomain === '') {
            // shopify.session middleware guarantees this attribute is set;
            // missing it means the middleware was misconfigured or skipped.
            return $this->error('Embedded session did not resolve a shop domain.', 500);
        }

        $code = trim((string) $request->input('code', ''));
        if ($code === '') {
            return $this->error('Connection code is required.', 422);
        }

        // Look up the professional_id stored against this code in Redis (30 min TTL).
        $professionalId = Cache::pull("shopify:embed:connect:{$code}");
        if (! $professionalId) {
            return $this->error('Invalid or expired connection code. Please generate a new one from your Partna dashboard.', 422);
        }

        // Guard: shop already linked to a different brand.
        $alreadyTaken = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('professional_id', '!=', $professionalId)
            ->whereRaw("provider_metadata->>'shop_domain' = ?", [$shopDomain])
            ->exists();

        if ($alreadyTaken) {
            return $this->error('This Shopify store is already connected to a different Partna account.', 409);
        }

        // Guard: this brand already has a different shop connected.
        $existing = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if ($existing) {
            $existingShop = $existing->provider_metadata['shop_domain'] ?? null;
            if ($existingShop && $existingShop !== $shopDomain) {
                return $this->error(
                    "This Partna account is already connected to {$existingShop}. Disconnect it first.",
                    409
                );
            }

            // Merge the shop_domain into provider_metadata so the generated column resolves.
            $existing->mergeProviderMetadata(['shop_domain' => $shopDomain]);
        } else {
            // No integration yet — create a minimal one. The brand will complete
            // the full Shopify OAuth from the embedded setup wizard.
            ProfessionalIntegration::create([
                'professional_id' => $professionalId,
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
                'external_account_id' => $shopDomain,
                'provider_metadata' => ['shop_domain' => $shopDomain],
            ]);
        }

        return $this->success(['connected' => true]);
    }
}
