<?php

namespace App\Services\Auth;

/**
 * Standard Webhooks signature verification + helpers for Supabase Auth Hooks.
 *
 * Spec: https://www.standardwebhooks.com/
 * Header format:
 *   webhook-id:        unique message id (used in signature input)
 *   webhook-timestamp: unix seconds (used in signature input + tolerance check)
 *   webhook-signature: "v1,<base64-sig> [v1,<rotated-sig>]" — space-separated for rotation
 *
 * Signature input is exactly: "{id}.{timestamp}.{body}" — HMAC-SHA256 with the
 * shared secret, base64-encoded.
 */
class SupabaseAuthHookService
{
    /** Reject signed messages older than this — replay-attack defense. */
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function verifySignature(string $id, string $timestamp, string $signatureHeader, string $rawBody): bool
    {
        $secret = (string) config('supabase.auth_hook_secret');
        if ($secret === '') {
            // Fail-closed: misconfiguration is a deploy bug, not a runtime question.
            return false;
        }

        // Replay defense: reject ancient timestamps.
        $ts = (int) $timestamp;
        if ($ts <= 0 || abs(time() - $ts) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return false;
        }

        $signedContent = "{$id}.{$timestamp}.{$rawBody}";
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

        // The header may contain multiple "v1,<sig>" tokens space-separated to
        // support secret rotation. Accept if ANY matches.
        foreach (explode(' ', trim($signatureHeader)) as $candidate) {
            if (! str_starts_with($candidate, 'v1,')) {
                continue;
            }
            $candidateSig = substr($candidate, 3);
            if (hash_equals($expected, $candidateSig)) {
                return true;
            }
        }

        return false;
    }
}
