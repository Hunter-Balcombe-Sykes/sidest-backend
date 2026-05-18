<?php

namespace App\Services\Email;

/**
 * Verifies the HMAC-SHA256 signature on Supabase Send Email Hook requests
 * per the Standard Webhooks spec (https://www.standardwebhooks.com/).
 *
 * Signed payload format:
 *     {webhook_id}.{webhook_timestamp}.{raw_body}
 *
 * Expected header:
 *     webhook-signature: v1,<base64-encoded-hmac-sha256>
 *     (space-separated additional signatures permitted during secret rotation)
 *
 * Supabase issues secrets in the form `v1,whsec_<base64-bytes>`; the bytes
 * after `whsec_` are the actual signing key. We strip the prefix and base64-
 * decode before HMAC.
 */
class SupabaseEmailHookSignatureVerifier
{
    /** Tolerance window for replay protection (seconds). */
    private const TIMESTAMP_TOLERANCE = 300;

    /**
     * @param  string  $configuredSecret  raw value from config('services.supabase.email_hook_secret')
     * @return bool true when at least one v1 signature in the header matches
     */
    public function verify(
        string $configuredSecret,
        string $webhookId,
        string $webhookTimestamp,
        string $webhookSignatureHeader,
        string $rawBody,
    ): bool {
        if ($configuredSecret === '' || $webhookId === '' || $webhookTimestamp === '' || $webhookSignatureHeader === '') {
            return false;
        }

        // Reject obvious replays — timestamps must be within tolerance of now.
        if (! ctype_digit($webhookTimestamp)) {
            return false;
        }
        $now = time();
        $ts = (int) $webhookTimestamp;
        if (abs($now - $ts) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        $secretBytes = $this->decodeSecret($configuredSecret);
        if ($secretBytes === null) {
            return false;
        }

        $signedPayload = $webhookId.'.'.$webhookTimestamp.'.'.$rawBody;
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $secretBytes, true));

        foreach ($this->parseSignatures($webhookSignatureHeader) as $candidate) {
            if (hash_equals($expectedSignature, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null binary signing-key bytes, or null if the format is unrecognised
     */
    private function decodeSecret(string $configuredSecret): ?string
    {
        // Supabase format: `v1,whsec_<base64>` — strip prefix, decode the rest.
        if (str_starts_with($configuredSecret, 'v1,whsec_')) {
            $b64 = substr($configuredSecret, strlen('v1,whsec_'));
            $bytes = base64_decode($b64, true);

            return $bytes === false ? null : $bytes;
        }

        // Bare base64 (legacy / manual rotations)
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $configuredSecret)) {
            $bytes = base64_decode($configuredSecret, true);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }

        // Plain string secret — use bytes directly.
        return $configuredSecret;
    }

    /**
     * The webhook-signature header is `v1,<sig> v1,<sig2> ...` — space-separated
     * during rotation windows. We only honour v1 signatures.
     *
     * @return list<string> base64 signatures, in header order
     */
    private function parseSignatures(string $header): array
    {
        $sigs = [];
        foreach (preg_split('/\s+/', trim($header)) as $part) {
            if ($part === '' || ! str_starts_with($part, 'v1,')) {
                continue;
            }
            $sigs[] = substr($part, 3);
        }

        return $sigs;
    }
}
