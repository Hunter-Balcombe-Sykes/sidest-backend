<?php

namespace App\Http\Middleware\Auth;

use App\Services\Shopify\ShopifyShopResolver;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Verifies a Shopify session token (JWT) sent by an admin UI extension.
//
// Shopify-issued session tokens are HS256-signed with the app's API secret.
// They embed the destination shop in the `dest` claim, the app's client ID
// in the `aud` claim, and short expiry (~60s) in `exp`. We verify all three
// before resolving the brand professional from the shop domain — same shape
// the embedded-key middleware exposes downstream so controllers can be auth-
// agnostic.
//
// Docs: https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
class VerifyShopifySessionToken
{
    public function __construct(
        private readonly ShopifyShopResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.shopify.api_secret');
        $expectedAud = (string) config('services.shopify.api_key');

        if ($secret === '' || $expectedAud === '') {
            return response()->json([
                'message' => 'Shopify session-token verification is not configured on this server.',
            ], 500);
        }

        $token = (string) str_replace('Bearer ', '', $request->header('Authorization', ''));

        if ($token === '') {
            return response()->json(['message' => 'Missing session token.'], 401);
        }

        try {
            $claims = (array) JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid session token.'], 401);
        }

        // Audience must match this app's client ID — guards against tokens
        // issued for a different Shopify app being replayed against ours.
        $aud = (string) ($claims['aud'] ?? '');
        if (! hash_equals($expectedAud, $aud)) {
            return response()->json(['message' => 'Session token audience mismatch.'], 401);
        }

        // Pull the shop domain out of the `dest` claim and normalise.
        // Shape: https://{shop}.myshopify.com (no path).
        $dest = (string) ($claims['dest'] ?? '');
        $shopDomain = strtolower(parse_url($dest, PHP_URL_HOST) ?? '');

        if ($shopDomain === '' || ! str_ends_with($shopDomain, '.myshopify.com')) {
            return response()->json(['message' => 'Invalid session token destination.'], 401);
        }

        $professionalId = $this->resolver->resolveProfessionalId($shopDomain);

        if ($professionalId === null) {
            return response()->json([
                'error' => 'shop_not_connected',
                'message' => 'No Side St account is linked to this Shopify store.',
            ], 404);
        }

        // Match the attribute name used by VerifyEmbeddedApiKey so controllers
        // can be reused regardless of which auth path got them here.
        $request->attributes->set('embedded_professional_id', $professionalId);

        return $next($request);
    }
}
