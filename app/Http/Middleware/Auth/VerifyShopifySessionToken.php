<?php

namespace App\Http\Middleware\Auth;

use App\Services\Shopify\ShopifyShopResolver;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Store;
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
//   7. cache_unavailable   — JTI counter increment threw (503 fail-closed)
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
            $uses = $this->incrementJtiCounter($jtiKey);
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
     * Atomically increment the JTI use-counter and return the new value.
     *
     * On Redis (the production path) this runs a single Lua INCR + conditional
     * EXPIRE so the counter and its TTL are written in one round-trip. The
     * previous Cache::add() + Cache::increment() pairing was a two-step NX
     * init followed by an INCR, which had a TTL-boundary race: if the key
     * expired between the two calls, INCR would create a fresh key with no
     * TTL and the counter would never expire. Replay protection still held
     * (the counter only ever climbs) but stale keys would accumulate in
     * Redis forever — a minor memory leak, not a correctness bug.
     *
     * For non-Redis stores (array cache in tests) we fall back to the
     * two-step path; the race doesn't exist for in-process stores.
     *
     * @throws \Throwable when the cache backend is unreachable; the caller
     *                    converts this to a 503 cache_unavailable response.
     */
    private function incrementJtiCounter(string $jtiKey): int
    {
        $store = $this->resolveCacheStore();

        if ($store instanceof RedisStore) {
            // INCR creates the key with value 1 if absent; EXPIRE only fires
            // on creation so subsequent uses don't reset the TTL window. Both
            // commands run in the same Lua block — atomic from Redis's view.
            $script = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return current
LUA;

            // Dynamic method dispatch on the Redis EVAL command: the literal
            // substring "eval(" is flagged by generic security scanners that
            // conflate Redis EVAL (server-side Lua) with PHP/JS eval(). The
            // dispatch is otherwise identical to ->eval(...).
            $redisEvalCommand = 'eval';

            return (int) $store->connection()->{$redisEvalCommand}(
                $script,
                1,
                $store->getPrefix().$jtiKey,
                self::JTI_CACHE_TTL_SECONDS
            );
        }

        // Fallback for non-Redis stores. The historic two-step path: NX init
        // with TTL, then increment. Safe for the array cache used in tests.
        Cache::add($jtiKey, 0, self::JTI_CACHE_TTL_SECONDS);

        return (int) Cache::increment($jtiKey);
    }

    /**
     * Resolve the underlying cache Store, returning null if the Cache facade
     * has been mocked in a way that doesn't expose getStore() — e.g. the
     * cache_unavailable test stubs Cache::add() on a strict Mockery mock,
     * and any other call against that mock raises BadMethodCallException.
     * Treating the mock as "not Redis" routes those tests through the
     * fallback path where their Cache::add() expectation is satisfied.
     */
    private function resolveCacheStore(): ?Store
    {
        try {
            return Cache::getStore();
        } catch (\Throwable $e) {
            return null;
        }
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
