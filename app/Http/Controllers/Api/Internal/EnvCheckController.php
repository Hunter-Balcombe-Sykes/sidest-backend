<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\Diagnostics\EnvCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-diagnostic endpoint that reports which required/recommended config
 * values are blank on the deployed environment.
 *
 * Independent of Supabase JWT auth on purpose — the whole point is to be
 * usable when other auth subsystems are themselves misconfigured. Gated
 * by a single shared-secret header (`X-Internal-Token`) compared against
 * `partna.internal_env_check_token`.
 *
 * Responses:
 *   503 — endpoint not configured (no server-side token set)
 *   403 — token missing or mismatched
 *   200 — JSON report (status=ok|fail, required_missing, recommended_missing)
 */
class EnvCheckController extends Controller
{
    public function __invoke(Request $request, EnvCheckService $service): JsonResponse
    {
        $expected = (string) config('partna.internal_env_check_token', '');

        if ($expected === '') {
            return response()->json([
                'message' => 'Endpoint not configured. Set INTERNAL_ENV_CHECK_TOKEN to enable.',
            ], 503);
        }

        $provided = (string) $request->header('X-Internal-Token', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($service->generate());
    }
}
