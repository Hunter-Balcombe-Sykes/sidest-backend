<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// Dual-auth dispatcher for the /internal/embedded/* setup-wizard routes
// during the cutover window from shared-key to session-token JWT auth.
//
// Inspects the Authorization header structure:
//   - Bearer <JWT> (3 dot-separated segments)  → VerifyShopifySessionToken
//   - Bearer <static-key> (otherwise)          → VerifyEmbeddedApiKey
//
// Lets Remix flip the wizard routes from static key to JWT without an atomic
// deploy. Once the Remix side ships session-token forwarding (Phase 4 of the
// embedded auth rebuild plan), `auth_path: static_key` counts in this log
// should drop to zero — that's the signal to remove this dispatcher and the
// static-key path entirely in Phase 5b.
//
// DEPRECATED on arrival: this middleware, the embedded.key alias, and
// VerifyEmbeddedApiKey all go away in Phase 8.
// Plan: ~/.claude/plans/we-spent-a-long-humming-phoenix.md
class EmbeddedDualAuth
{
    public function __construct(
        private readonly VerifyShopifySessionToken $sessionToken,
        private readonly VerifyEmbeddedApiKey $apiKey,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $auth = (string) $request->header('Authorization', '');

        // JWTs have exactly two dots (header.payload.signature). Static keys
        // are opaque tokens with no required structure — treat anything
        // without 3 dot-separated segments as a static key candidate. This
        // is a structural hint, not a security boundary: the sub-middleware
        // still validates the token cryptographically.
        $looksLikeJwt = str_starts_with($auth, 'Bearer ') && substr_count($auth, '.') === 2;

        Log::info('embedded.dual_auth.routed', [
            'path' => $request->path(),
            'auth_path' => $looksLikeJwt ? 'session_token' : 'static_key',
        ]);

        return $looksLikeJwt
            ? $this->sessionToken->handle($request, $next)
            : $this->apiKey->handle($request, $next);
    }
}
