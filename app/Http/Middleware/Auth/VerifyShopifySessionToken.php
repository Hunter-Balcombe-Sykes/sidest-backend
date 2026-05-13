<?php

namespace App\Http\Middleware\Auth;

use App\Services\Shopify\ShopifyShopResolver;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// Verifies a Shopify-signed session JWT and binds the request to a tenant.
//
// Tenant identity is bound to the JWT signature end-to-end: App Bridge signs
// the token with SHOPIFY_API_SECRET, the Remix SDK forwards it verbatim, and
// this middleware decodes + validates every claim before stashing the resolved
// professional on the request. There is no shared static key, no trusted
// X-Shopify-Shop header — `dest` is the sole source of tenant identity.
//
// Replay protection: 120s Redis-backed JTI counter. FAIL-CLOSED — if the cache
// backend is unreachable we return 503, not 200, because a multi-pod deploy
// with a non-clustered cache would otherwise let an attacker replay any
// captured token against a different pod.
//
// Each JTI is allowed up to services.shopify.jti_max_uses (default: 25) uses
// within the TTL. Remix SSR runs multiple loaders in parallel — and each loader
// may make multiple backend calls — all sharing one JWT, so strict one-time-use
// would reject legitimate traffic on every page load. 25 uses still blocks
// brute-force replay (rate-limited at 60 req/min per shop) while covering the
// realistic SSR fan-out (≤6 calls per page load).
//
// Validation order (every reject is a distinct reason code for observability):
//   1. token_missing       — no Authorization header
//   2. sig_invalid         — JWT::decode threw (covers signature, exp, nbf)
//   3. aud_mismatch        — aud != SHOPIFY_API_KEY
//   4. dest_invalid        — dest host does not end .myshopify.com
//   5. iss_mismatch        — iss host != dest host
//   6. jti_missing         — no jti claim
//   7. cache_unavailable   — Cache::add threw (503 fail-closed)
//   8. jti_replay          — jti use-count exceeded jti_max_uses within the 120s window
//   9. shop_unlinked       — no professional linked to this shop (lenient mode SKIPS this)
//
// Modes:
//   default     — runs every step. Use on routes that operate on a linked shop.
//   `:lenient`  — runs steps 1-8, skips step 9 (shop resolution). Use on routes
//                 that intentionally operate before the shop is linked, e.g.
//                 the connect-account endpoint that performs the linking itself.
//                 Sets embedded_shop_domain on the request but NOT
//                 embedded_professional_id (caller resolves another way).
//
// Docs: https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
class VerifyShopifySessionToken
{
    private const JTI_CACHE_TTL_SECONDS = 120;

    private const JTI_CACHE_KEY_PREFIX = 'partna:shopify-jti:';

    // Default max uses per JTI within the TTL. Configurable so tests can set it
    // to 1 and keep the existing one-time-use assertion (via jti_max_uses config).
    private const JTI_MAX_USES_DEFAULT = 25;

    // Clock skew tolerance for exp/nbf checks. Shopify-signed tokens are short
    // (60s exp) so this is generous; tighter would cause spurious 401s on
    // pods whose NTP has drifted by a few seconds.
    private const CLOCK_LEEWAY_SECONDS = 10;

    public function __construct(
        private readonly ShopifyShopResolver $resolver,
    ) {}

    /**
     * @param  ?string  $mode  null (default) for full validation including shop
     *                         resolution; 'lenient' to skip the resolver step
     *                         (used by routes that link the shop themselves).
     * @return Response 401 on auth failure, 404 on unknown shop (default mode
     *                  only), 503 on cache unavailable, otherwise delegates to
     *                  $next. Sets `embedded_shop_domain` + `embedded_shopify_user_id`
     *                  always; sets `embedded_professional_id` only in default mode.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $startedAt = microtime(true);
        $secret = (string) config('services.shopify.api_secret');
        $expectedAud = (string) config('services.shopify.api_key');

        if ($secret === '' || $expectedAud === '') {
            // Misconfiguration is a deploy bug, not a request error. Surface as
            // an exception so it shows up in Nightwatch as a 500 (deployment
            // health), not a 401 (auth health). A silent 401 would mask the
            // root cause behind "unauthenticated requests".
            throw new \RuntimeException(
                'Shopify session token middleware misconfigured: SHOPIFY_API_KEY and SHOPIFY_API_SECRET are required.'
            );
        }

        $token = (string) str_replace('Bearer ', '', $request->header('Authorization', ''));
        if ($token === '') {
            return $this->reject($request, 'token_missing', 401, $startedAt);
        }

        try {
            JWT::$leeway = self::CLOCK_LEEWAY_SECONDS;
            $claims = (array) JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            return $this->reject($request, 'sig_invalid', 401, $startedAt, [
                'error_class' => class_basename($e),
            ]);
        }

        $aud = (string) ($claims['aud'] ?? '');
        if (! hash_equals($expectedAud, $aud)) {
            return $this->reject($request, 'aud_mismatch', 401, $startedAt);
        }

        $dest = (string) ($claims['dest'] ?? '');
        $destHost = strtolower((string) parse_url($dest, PHP_URL_HOST));
        if ($destHost === '' || ! str_ends_with($destHost, '.myshopify.com')) {
            return $this->reject($request, 'dest_invalid', 401, $startedAt);
        }

        $iss = (string) ($claims['iss'] ?? '');
        $issHost = strtolower((string) parse_url($iss, PHP_URL_HOST));
        if ($issHost === '' || $issHost !== $destHost) {
            return $this->reject($request, 'iss_mismatch', 401, $startedAt, [
                'shop' => $destHost,
            ]);
        }

        $jti = (string) ($claims['jti'] ?? '');
        if ($jti === '') {
            return $this->reject($request, 'jti_missing', 401, $startedAt, [
                'shop' => $destHost,
            ]);
        }

        $jtiKey = self::JTI_CACHE_KEY_PREFIX.$jti;

        try {
            // Initialise the counter on first use (NX — only if absent, with TTL).
            // Concurrent first-use calls: only one wins the NX set; others see
            // the key already exists. Both then increment atomically. The TTL is
            // set on creation and not reset by subsequent increments.
            Cache::add($jtiKey, 0, self::JTI_CACHE_TTL_SECONDS);
            $uses = Cache::increment($jtiKey);
        } catch (\Throwable $e) {
            Log::error('shopify.session.cache_unavailable', [
                'error_class' => class_basename($e),
                'shop' => $destHost,
            ]);

            return $this->reject($request, 'cache_unavailable', 503, $startedAt, [
                'shop' => $destHost,
            ]);
        }

        if ($uses > $this->jtiMaxUses()) {
            return $this->reject($request, 'jti_replay', 401, $startedAt, [
                'shop' => $destHost,
                'jti_hash' => hash('sha256', $jti),
            ]);
        }

        $request->attributes->set('embedded_shop_domain', $destHost);
        $request->attributes->set('embedded_shopify_user_id', (string) ($claims['sub'] ?? ''));

        if ($mode === 'lenient') {
            Log::info('shopify.session.ok', [
                'shop' => $destHost,
                'mode' => 'lenient',
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return $next($request);
        }

        $professionalId = $this->resolver->resolveProfessionalId($destHost);
        if ($professionalId === null) {
            return $this->reject($request, 'shop_unlinked', 404, $startedAt, [
                'shop' => $destHost,
            ]);
        }

        $request->attributes->set('embedded_professional_id', $professionalId);

        Log::info('shopify.session.ok', [
            'shop' => $destHost,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $next($request);
    }

    /**
     * Maximum JTI uses allowed within the cache TTL.
     * Reads from services.shopify.jti_max_uses so tests can override to 1.
     */
    private function jtiMaxUses(): int
    {
        return (int) config('services.shopify.jti_max_uses', self::JTI_MAX_USES_DEFAULT);
    }

    /**
     * Emit a structured warning log and return a JSON response.
     *
     * @param  array<string, mixed>  $extra
     */
    private function reject(Request $request, string $reason, int $status, float $startedAt, array $extra = []): Response
    {
        Log::warning('shopify.session.failed', array_merge([
            'reason' => $reason,
            'path' => $request->path(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ], $extra));

        return response()->json(['message' => "auth_{$reason}"], $status);
    }
}
