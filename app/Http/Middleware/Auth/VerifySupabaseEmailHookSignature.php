<?php

namespace App\Http\Middleware\Auth;

use App\Services\Email\SupabaseEmailHookSignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates POST /internal/email-hooks/supabase by verifying the Standard Webhooks
 * HMAC signature Supabase attaches to every send-email-hook delivery.
 *
 * Rejects with 401 on any failure. The secret lives in
 * config('services.supabase.email_hook_secret'); when unset (local dev),
 * the route returns 503 so nobody accidentally ships an unguarded endpoint.
 */
class VerifySupabaseEmailHookSignature
{
    public function __construct(
        private readonly SupabaseEmailHookSignatureVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.supabase.email_hook_secret', '');
        if ($secret === '') {
            Log::warning('supabase.email_hook.misconfigured', ['reason' => 'secret_missing']);

            return response()->json([
                'error' => true,
                'message' => 'Email hook is not configured.',
            ], 503);
        }

        $webhookId = (string) $request->header('webhook-id', '');
        $webhookTimestamp = (string) $request->header('webhook-timestamp', '');
        $webhookSignature = (string) $request->header('webhook-signature', '');
        $rawBody = (string) $request->getContent();

        $valid = $this->verifier->verify(
            configuredSecret: $secret,
            webhookId: $webhookId,
            webhookTimestamp: $webhookTimestamp,
            webhookSignatureHeader: $webhookSignature,
            rawBody: $rawBody,
        );

        if (! $valid) {
            Log::warning('supabase.email_hook.signature_failed', [
                'webhook_id' => $webhookId,
                'webhook_timestamp' => $webhookTimestamp,
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        return $next($request);
    }
}
