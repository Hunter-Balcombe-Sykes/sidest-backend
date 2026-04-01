<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class VerifySupabaseJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->getBearerToken($request);
        if (!$token) {
            return response()->json(['message' => 'Missing Bearer token'], 401);
        }

        // 1) Try verify via JWKS (asymmetric signing)
        try {
            $claims = $this->verifyWithJwks($token);

            // Validate issuer/audience (extra safety)
            if (!$this->claimsMatchConfig($claims)) {
                return response()->json(['message' => 'Invalid token claims'], 401);
            }

            $uid = $claims['sub'] ?? null;
            if (!$uid) {
                return response()->json(['message' => 'Token missing sub'], 401);
            }

            $this->setSupabaseContext($request, $uid, $claims);
            return $next($request);
        } catch (\Throwable $e) {
            // 2) Fallback for legacy/shared-secret setups:
            // Supabase recommends verifying by calling Auth server /user. :contentReference[oaicite:2]{index=2}
            try {
                $uid = $this->verifyWithAuthServer($token);
                if (!$uid) {
                    return response()->json(['message' => 'Invalid token'], 401);
                }

                $this->setSupabaseContext($request, $uid);
                return $next($request);
            } catch (\Throwable $e2) {
                Log::warning('JWT verification failed', [
                    'reason' => $e2->getMessage(),
                    'ip'     => $request->ip(),
                ]);
                return response()->json(['message' => 'Invalid token'], 401);
            }
        }
    }

    private function getBearerToken(Request $request): ?string
    {
        $auth = (string) $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
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
        $kid = $header['kid'] ?? null;
        if (!$kid) {
            throw new \RuntimeException('JWT header missing kid');
        }

        $jwksUrl = config('supabase.jwks_url');
        if (!$jwksUrl) {
            throw new \RuntimeException('Missing SUPABASE_JWKS_URL');
        }

        $jwks = Cache::remember('supabase:jwks', config('supabase.jwks_cache_seconds', 600), function () use ($jwksUrl) {
            $res = Http::timeout(5)->get($jwksUrl);
            if (!$res->ok()) {
                throw new \RuntimeException('Failed to fetch JWKS');
            }
            return $res->json();
        });

        // If your project isn't using asymmetric keys, JWKS may be empty. :contentReference[oaicite:3]{index=3}
        if (empty($jwks['keys'])) {
            throw new \RuntimeException('JWKS empty');
        }

        $keys = JWK::parseKeySet($jwks);
        $key = $keys[$kid] ?? null;
        if (!$key) {
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

        if (!$baseUrl || !$anonKey) {
            throw new \RuntimeException('Missing SUPABASE_URL or SUPABASE_ANON_KEY');
        }

        $res = Http::timeout(5)
            ->withHeaders([
                'apikey' => $anonKey,
                'Authorization' => 'Bearer ' . $jwt,
            ])
            ->get($baseUrl . '/auth/v1/user');

        if (!$res->ok()) {
            return null;
        }

        $user = $res->json();
        return $user['id'] ?? null; // Supabase user id
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
                if (!in_array($audExpected, $aud, true)) return false;
            } else {
                if ($aud !== $audExpected) return false;
            }
        }

        return true;
    }

    private function b64urlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode($data) ?: '';
    }
}
