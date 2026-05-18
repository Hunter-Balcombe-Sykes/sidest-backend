<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthFactorEventRepository;
use App\Services\Auth\SupabaseAuthHookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Supabase Auth Hook callbacks.
 *
 * Currently handles only the MFA Verification Hook — every TOTP/Phone
 * verification attempt is announced to us *before* Supabase promotes
 * the session to aal2. We respond with {decision: "continue"} to allow,
 * or {decision: "reject", message: "..."} to refuse.
 *
 * Brute-force defense: after N failed verifies in the rolling window
 * (configurable in partna.mfa.*), we reject further attempts and record
 * the rejection so subsequent window queries keep flagging the user as
 * in-cooldown.
 *
 * Signature verification is the FIRST thing this does — unsigned or
 * forged requests get 401 before any DB access. Standard Webhooks spec.
 */
class SupabaseAuthHookController extends Controller
{
    public function __construct(
        private readonly SupabaseAuthHookService $hookService,
        private readonly AuthFactorEventRepository $repo,
    ) {}

    public function mfaVerification(Request $request): JsonResponse
    {
        $id = (string) $request->header('webhook-id', '');
        $timestamp = (string) $request->header('webhook-timestamp', '');
        $signature = (string) $request->header('webhook-signature', '');
        $rawBody = $request->getContent();

        if (! $this->hookService->verifySignature($id, $timestamp, $signature, $rawBody)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $userId = (string) ($payload['user_id'] ?? '');
        $factorId = (string) ($payload['factor_id'] ?? '');
        $factorType = $payload['factor_type'] ?? null;
        $valid = (bool) ($payload['valid'] ?? false);

        if ($userId === '' || $factorId === '') {
            return response()->json(['message' => 'Malformed payload'], 400);
        }

        $ip = $request->ip();
        $userAgent = (string) $request->userAgent();

        if ($valid) {
            $this->repo->record(
                userId: $userId,
                eventType: 'verify_success',
                factorId: $factorId,
                factorType: $factorType,
                ip: $ip,
                userAgent: $userAgent,
            );
            return response()->json(['decision' => 'continue']);
        }

        // Failed verify path.
        $maxFailures = (int) config('partna.mfa.verify_max_failures', 5);
        $windowSeconds = (int) config('partna.mfa.verify_failure_window_seconds', 300);

        $recentFailures = $this->repo->countRecentFailures($userId, $factorId, $windowSeconds);

        if ($recentFailures >= $maxFailures) {
            $this->repo->record(
                userId: $userId,
                eventType: 'verify_rejected_by_hook',
                factorId: $factorId,
                factorType: $factorType,
                ip: $ip,
                userAgent: $userAgent,
                metadata: ['recent_failures' => $recentFailures, 'window_seconds' => $windowSeconds],
            );
            return response()->json([
                'decision' => 'reject',
                'message' => 'Too many failed verification attempts. Try again in '.ceil($windowSeconds / 60).' minutes.',
            ]);
        }

        $this->repo->record(
            userId: $userId,
            eventType: 'verify_failed',
            factorId: $factorId,
            factorType: $factorType,
            ip: $ip,
            userAgent: $userAgent,
        );

        return response()->json(['decision' => 'continue']);
    }
}
