<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        // The read-then-write region must be atomic — without it, two parallel
        // connect calls (different brands, same shop) can both pass the
        // "alreadyTaken" exists() guard and both INSERT, leaving the second
        // request to fail on the partial UNIQUE index on shopify_shop_domain
        // with an ugly 500. The DB-level unique index is still the ultimate
        // serializer (see professional_integrations_shopify_domain_uq in the
        // v2 baseline migration); the transaction + lock + catch below translate
        // a lost race into a clean 409 instead of letting it surface as 500.
        try {
            return DB::transaction(function () use ($professionalId, $shopDomain) {
                $alreadyTaken = ProfessionalIntegration::query()
                    ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                    ->where('professional_id', '!=', $professionalId)
                    ->whereRaw("provider_metadata->>'shop_domain' = ?", [$shopDomain])
                    ->exists();

                if ($alreadyTaken) {
                    return $this->error('This Shopify store is already connected to a different Partna account.', 409);
                }

                // lockForUpdate on the brand's own integration row serializes
                // same-brand double-clicks: the second concurrent caller waits
                // until the first commits, then re-reads the merged shop_domain.
                $existing = ProfessionalIntegration::query()
                    ->where('professional_id', $professionalId)
                    ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $existingShop = $existing->provider_metadata['shop_domain'] ?? null;

                    // Only the idempotent same-shop reconnect is allowed to mutate
                    // an existing integration row. Two reject cases collapse here:
                    //   1. A different shop_domain is already linked — brand must
                    //      explicitly disconnect before reconnecting elsewhere.
                    //   2. shop_domain is NULL (partial install / legacy / manual
                    //      DB edit) — silently rebinding a code-validated shop
                    //      onto a dangling row would be a tenant-isolation hole.
                    //      The brand cannot self-recover from this state; ops
                    //      intervention is required to clear the stale row.
                    if ($existingShop !== $shopDomain) {
                        $message = $existingShop
                            ? "This Partna account is already connected to {$existingShop}. Disconnect it first."
                            : 'This Partna account has an incomplete Shopify integration on file. Please contact support to clear it before reconnecting.';

                        return $this->error($message, 409);
                    }

                    // Recovery: existing shop_domain already matches. The merge is
                    // a no-op at the JSONB level (`{shop_domain: X} || {shop_domain: X}`)
                    // but we keep it so the response is symmetric with the create
                    // branch and updated_at advances.
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
            });
        } catch (UniqueConstraintViolationException) {
            // Race past the exists() guard: another connect for a different
            // brand won the partial UNIQUE on shopify_shop_domain (or the
            // (professional_id, provider) unique index on a same-brand
            // double-create) between our read and our write. Either way, the
            // shop is now claimed — return the same 409 the guard would have.
            return $this->error('This Shopify store is already connected to a different Partna account.', 409);
        }
    }
}
