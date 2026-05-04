<?php

namespace App\Http\Middleware\Context;

use App\Services\Cache\ProfessionalCacheService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// V2: Loads authenticated professional into request context via cache. Rejects suspended/missing accounts.
class LoadCurrentProfessional
{
    public function __construct(
        private ProfessionalCacheService $professionalCache
    ) {}

    public function handle(Request $request, Closure $next): Response
    {

        Log::debug('LoadCurrentProfessional start');

        $uid = $request->attributes->get('supabase_uid');
        if (! $uid) {
            Log::debug('LoadCurrentProfessional missing uid');

            return response()->json(['message' => 'Missing uid'], 401);
        }

        // Supabase sub claim is always a UUID; any non-UUID string indicates a routing/middleware misconfiguration.
        if (! Str::isUuid($uid)) {
            Log::warning('LoadCurrentProfessional invalid uid format', ['uid' => $uid]);

            return response()->json(['message' => 'Invalid uid'], 401);
        }

        Log::debug('LoadCurrentProfessional before cache getByAuthId', ['uid' => $uid]);

        // Use cache service instead of a direct query
        $professional = $this->professionalCache->getByAuthId($uid);

        Log::debug('LoadCurrentProfessional after cache getByAuthId', [
            'uid' => $uid,
            'found' => (bool) $professional,
        ]);

        if (! $professional) {
            // Important: /api/bootstrap should create this row
            Log::debug('LoadCurrentProfessional no professional for uid', ['uid' => $uid]);

            return response()->json([
                'message' => 'professional profile missing. Call /api/bootstrap first.',
            ], 403);
        }

        $status = $professional->status ?? 'active';
        if (! in_array($status, ['active', 'pending_deletion'], true)) {
            Log::debug('LoadCurrentProfessional blocked account', [
                'uid' => $uid,
                'status' => $status,
            ]);

            return response()->json([
                'message' => 'Your account is not active. Contact support.',
            ], 403);
        }

        $request->attributes->set('professional', $professional);

        Log::debug('LoadCurrentProfessional before next middleware');

        return $next($request);
    }
}
