<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// Verifies Cloudflare Turnstile tokens on public lead-capture endpoints.
// Bypassed when PARTNA_CAPTCHA_ENABLED=false (default) so frontends can add
// the Turnstile widget before the gate is flipped on in production.
class VerifyTurnstileCaptcha
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('partna.features.captcha', false)) {
            return $next($request);
        }

        $token = $request->input('cf_turnstile_response');
        if (! is_string($token) || trim($token) === '') {
            return response()->json(['message' => 'CAPTCHA token missing.'], 422);
        }

        $secretKey = config('services.turnstile.secret_key');
        if (! $secretKey) {
            Log::error('Turnstile secret key not configured');

            return response()->json(['message' => 'CAPTCHA verification unavailable.'], 503);
        }

        try {
            $res = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);

            $data = $res->json() ?? [];

            if (! ($data['success'] ?? false)) {
                Log::warning('Turnstile CAPTCHA failed', [
                    'error_codes' => $data['error-codes'] ?? [],
                    'ip' => $request->ip(),
                ]);

                return response()->json(['message' => 'CAPTCHA verification failed.'], 422);
            }
        } catch (\Throwable $e) {
            // Fail closed: a Cloudflare outage means submissions are blocked rather
            // than leaving the gate open for bots to exploit the outage window.
            Log::error('Turnstile verification request failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'CAPTCHA verification unavailable.'], 503);
        }

        return $next($request);
    }
}
