<?php

namespace App\Http\Middleware\Auth;

use App\Services\Cache\CacheLockService;
use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// V2: JWT authentication via Supabase JWKS (asymmetric). Falls back to Auth Server query. All authenticated routes require this.
class VerifySupabaseJwt
{
    /**
     * Process-local memo of parsed JWKS keys, keyed by a hash of the raw JWKS
     * payload. JWK::parseKeySet() rebuilds OpenSSL EC public-key resources from
     * JSON on every call (~150-300ms for ES256 — dominates the auth path) but
     * the JWKS itself rarely changes; caching the parsed Key map across requests
     * within a PHP-FPM worker drops repeat-request cost to a single signature
     * verification (~5-15ms). Hashing the payload means a Supabase signing-key
     * rotation invalidates the memo automatically (the JWKS Redis cache TTL is
     * 5 min, so we pick up rotations within that window). Capped at 4 entries
     * to bound long-running-worker memory across multiple rotations.
     *
     * @var array<string, array<string, \Firebase\JWT\Key>>
     */
    private static array $parsedKeysByJwksHash = [];

    /**
     * Hash of the most recently loaded JWKS payload. Identifies which entry of
     * $parsedKeysByJwksHash is the "current" map — used by the fast-path that
     * skips Redis when a recent parsed map already contains the requested kid.
     */
    private static ?string $jwksCurrentHash = null;

    /**
     * Monotonic millis of the last successful JWKS load (Redis hit OR closure
     * refresh). The Redis-skip fast path is only taken while this is fresh.
     */
    private static ?int $jwksLoadedAtMs = null;

    /**
     * Process-local TTL on the Redis-skip fast path. Independent of the upstream
     * Redis cache's TTL: this only governs how long a worker trusts its in-memory
     * parsed map without re-checking Redis. A new kid (rotation) bypasses the
     * fast path automatically — the cache.get below picks up the new payload.
     */
    private const JWKS_FRESH_TTL_MS = 60_000;

    public function __construct(private CacheLockService $cacheLock) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->getBearerToken($request);
        if (! $token) {
            return response()->json(['message' => 'Missing Bearer token'], 401);
        }

        // 1) Try verify via JWKS (asymmetric signing)
        try {
            $claims = $this->verifyWithJwks($token);

            // Validate issuer/audience (extra safety)
            if (! $this->claimsMatchConfig($claims)) {
                return response()->json(['message' => 'Invalid token claims'], 401);
            }

            $uid = $claims['sub'] ?? null;
            if (! $uid) {
                return response()->json(['message' => 'Token missing sub'], 401);
            }

            $this->setSupabaseContext($request, $uid, $claims);

            return $next($request);
        } catch (\Throwable $e) {
            // Log every JWKS failure before falling back — repeated infra-level failures
            // (e.g. network blocking JWKS fetches) are security-relevant and must be visible.
            Log::warning('JWT JWKS verification failed, falling back to auth server', [
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            // Fail-closed mode: refuse to fall back to Auth-Server during JWKS outage.
            // Set SUPABASE_JWKS_FAIL_CLOSED=true in production if you prefer hard failures
            // over the reduced-security fallback path.
            if (config('supabase.jwks_fail_closed', false)) {
                return response()->json(['message' => 'Service unavailable'], 503);
            }

            // 2) Fallback for legacy/shared-secret setups:
            // Supabase recommends verifying by calling Auth server /user. :contentReference[oaicite:2]{index=2}
            try {
                $uid = $this->verifyWithAuthServer($token);
                if (! $uid) {
                    return response()->json(['message' => 'Invalid token'], 401);
                }

                $this->setSupabaseContext($request, $uid);

                return $next($request);
            } catch (\Throwable $e2) {
                Log::warning('JWT verification failed', [
                    'reason' => $e2->getMessage(),
                    'ip' => $request->ip(),
                ]);

                return response()->json(['message' => 'Invalid token'], 401);
            }
        }
    }

    private function getBearerToken(Request $request): ?string
    {
        $auth = (string) $request->header('Authorization', '');
        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return trim(substr($auth, 7));
    }

    private function setSupabaseContext(Request $request, string $uid, ?array $claims = null): void
    {
        $request->attributes->set('supabase_uid', $uid);

        if ($claims !== null) {
            $request->attributes->set('supabase_claims', $claims);
        }

        // Nightwatch falls back to hidden context when no Laravel auth guard is resolved.
        if (class_exists(\Laravel\Nightwatch\Compatibility::class)) {
            \Laravel\Nightwatch\Compatibility::addUserIdToContext($uid);
        }
    }

    private function verifyWithJwks(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWT format');
        }

        $header = json_decode($this->b64urlDecode($parts[0]), true) ?: [];

        // Reject non-asymmetric algorithms before key lookup to prevent algorithm confusion attacks
        // (e.g. HS256 signed with the public key as the HMAC secret). RS256 and ES256 are both
        // asymmetric — Supabase issues ES256 by default for projects on migrated signing keys, so
        // both must be accepted here. NEVER add HS256 to this allowlist.
        $alg = $header['alg'] ?? null;
        if (! in_array($alg, ['RS256', 'ES256'], true)) {
            throw new \RuntimeException('JWT alg must be RS256 or ES256, got: '.($alg ?? 'none'));
        }

        $kid = $header['kid'] ?? null;
        if (! $kid) {
            throw new \RuntimeException('JWT header missing kid');
        }

        $nowMs = (int) (microtime(true) * 1000);

        // Redis-skip fast path: if this worker has loaded JWKS within the last
        // JWKS_FRESH_TTL_MS *and* the current parsed map already contains the
        // kid this token was signed with, verify directly. This eliminates the
        // 100–300ms supabase:jwks Redis round-trip (and any SWR-triggered
        // closure refresh that would otherwise fire HTTP at Supabase) from the
        // common authenticated-request path. Rotations bypass this branch
        // automatically — a new kid won't be in the memoised map, so we fall
        // through to the cache.get below which has its own (5 min) TTL.
        if (
            self::$jwksCurrentHash !== null
            && self::$jwksLoadedAtMs !== null
            && ($nowMs - self::$jwksLoadedAtMs) < self::JWKS_FRESH_TTL_MS
            && isset(self::$parsedKeysByJwksHash[self::$jwksCurrentHash][$kid])
        ) {
            JWT::$leeway = 60;
            $decoded = JWT::decode($jwt, self::$parsedKeysByJwksHash[self::$jwksCurrentHash][$kid]);

            return json_decode(json_encode($decoded), true) ?: [];
        }

        $jwksUrl = config('supabase.jwks_url');
        if (! $jwksUrl) {
            throw new \RuntimeException('Missing SUPABASE_JWKS_URL');
        }

        $jwks = $this->cacheLock->rememberLocked('supabase:jwks', config('supabase.jwks_cache_seconds', 300), function () use ($jwksUrl) {
            $res = Http::timeout(5)->get($jwksUrl);
            if (! $res->ok()) {
                throw new \RuntimeException('Failed to fetch JWKS');
            }

            $payload = $res->json();
            if (! is_array($payload)) {
                // Empty/invalid JSON body — never cache this. Throwing keeps the lock-release
                // path clean and lets the caller's error logging surface the infra problem.
                throw new \RuntimeException('JWKS response did not parse to an array');
            }

            return $payload;
        });

        // If your project isn't using asymmetric keys, JWKS may be empty. :contentReference[oaicite:3]{index=3}
        if (empty($jwks['keys'])) {
            throw new \RuntimeException('JWKS empty');
        }

        $jwksHash = md5((string) json_encode($jwks));
        if (! isset(self::$parsedKeysByJwksHash[$jwksHash])) {
            // Bound worker memory across multiple key rotations — drop the oldest
            // entry once we already hold a small set of recent JWKS payloads.
            if (count(self::$parsedKeysByJwksHash) >= 4) {
                array_shift(self::$parsedKeysByJwksHash);
            }
            self::$parsedKeysByJwksHash[$jwksHash] = JWK::parseKeySet($jwks);
        }

        // Mark this hash as the current JWKS map for the worker's fast path.
        // Updated on every successful Redis fetch so a rotation transition
        // pivots the fast path onto the new payload as soon as one request
        // pays the cache.get cost.
        self::$jwksCurrentHash = $jwksHash;
        self::$jwksLoadedAtMs = $nowMs;

        $keys = self::$parsedKeysByJwksHash[$jwksHash];
        $key = $keys[$kid] ?? null;
        if (! $key) {
            throw new \RuntimeException('No matching JWKS key for kid');
        }

        // Decode + verify signature + exp/nbf automatically
        JWT::$leeway = 60; // clock skew tolerance
        $decoded = JWT::decode($jwt, $key);

        return json_decode(json_encode($decoded), true) ?: [];
    }

    private function verifyWithAuthServer(string $jwt): ?string
    {
        $baseUrl = rtrim((string) config('supabase.url'), '/');
        $anonKey = (string) config('supabase.anon_key');

        if (! $baseUrl || ! $anonKey) {
            throw new \RuntimeException('Missing SUPABASE_URL or SUPABASE_ANON_KEY');
        }

        // Validate iss/aud from the token payload before calling Auth-Server.
        // Auth-Server verifies the signature, but doesn't protect against cross-project
        // tokens (a valid token from another Supabase project would pass otherwise).
        $claims = $this->extractJwtPayloadClaims($jwt);
        if (! $this->claimsMatchConfig($claims)) {
            return null;
        }

        $res = Http::timeout(5)
            ->withHeaders([
                'apikey' => $anonKey,
                'Authorization' => 'Bearer '.$jwt,
            ])
            ->get($baseUrl.'/auth/v1/user');

        if (! $res->ok()) {
            return null;
        }

        $user = $res->json();
        $uid = $user['id'] ?? null;

        // Reject if Supabase returns a non-UUID — guards against misconfig or unexpected response shapes.
        if (! $uid || ! $this->isValidUuid($uid)) {
            return null;
        }

        return $uid;
    }

    /** Decode JWT payload without signature verification — used only to read claims in the fallback path. */
    private function extractJwtPayloadClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        return json_decode($this->b64urlDecode($parts[1]), true) ?: [];
    }

    private function isValidUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private function claimsMatchConfig(array $claims): bool
    {
        $issExpected = (string) config('supabase.jwt_issuer');
        $audExpected = (string) config('supabase.jwt_audience');

        if ($issExpected && (($claims['iss'] ?? null) !== $issExpected)) {
            return false;
        }

        $aud = $claims['aud'] ?? null;
        if ($audExpected) {
            if (is_array($aud)) {
                if (! in_array($audExpected, $aud, true)) {
                    return false;
                }
            } else {
                if ($aud !== $audExpected) {
                    return false;
                }
            }
        }

        return true;
    }

    private function b64urlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($data) ?: '';
    }
}
