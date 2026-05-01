<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Handles connecting a Shopify shop to an existing Side St brand account.
// Called by the Sidest-Embedded app when a brand installs the Shopify app
// but their shop domain is not yet linked to a professional record.
// Auth: validates the SIDEST_EMBEDDED_API_KEY Bearer token manually (the
// shop can't be resolved yet, so we can't use the embedded.key middleware).
class EmbeddedConnectController extends ApiController
{
    /**
     * Connect a Shopify shop to a Side St account via a time-limited connection code.
     *
     * The code is generated in the Side St dashboard and stored in Redis for 30 minutes.
     * On success, the professional_integrations row is created or updated so that the
     * generated shopify_shop_domain column resolves to the shop.
     */
    public function connect(Request $request): JsonResponse
    {
        // Validate the embedded API key — same check as VerifyEmbeddedApiKey middleware.
        $expected = (string) config('services.embedded.api_key');
        if ($expected !== '') {
            $provided = (string) str_replace('Bearer ', '', $request->header('Authorization', ''));
            if ($provided === '' || ! hash_equals($expected, $provided)) {
                return $this->error('Invalid or missing embedded API key.', 403);
            }
        }

        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop', '')));
        if ($shopDomain === '') {
            return $this->error('Missing X-Shopify-Shop header.', 400);
        }

        $code = trim((string) $request->input('code', ''));
        if ($code === '') {
            return $this->error('Connection code is required.', 422);
        }

        // Look up the professional_id stored against this code in Redis (30 min TTL).
        $professionalId = Cache::pull("shopify:embed:connect:{$code}");
        if (! $professionalId) {
            return $this->error('Invalid or expired connection code. Please generate a new one from your Side St dashboard.', 422);
        }

        // Guard: shop already linked to a different brand.
        $alreadyTaken = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('professional_id', '!=', $professionalId)
            ->whereRaw("provider_metadata->>'shop_domain' = ?", [$shopDomain])
            ->exists();

        if ($alreadyTaken) {
            return $this->error('This Shopify store is already connected to a different Side St account.', 409);
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
                    "This Side St account is already connected to {$existingShop}. Disconnect it first.",
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
