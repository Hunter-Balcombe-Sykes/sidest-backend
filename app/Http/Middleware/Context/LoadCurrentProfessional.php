<?php

namespace App\Http\Middleware\Context;

use App\Services\Cache\ProfessionalCacheService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LoadCurrentProfessional
{
    public function __construct(
        private ProfessionalCacheService $professionalCache
    ) {}
    public function handle(Request $request, Closure $next): Response
    {

        Log::info('LoadCurrentProfessional start');

        $uid = $request->attributes->get('supabase_uid');
        if (!$uid) {
            Log::info('LoadCurrentProfessional missing uid');
            return response()->json(['message' => 'Missing uid'], 401);
        }

        Log::info('LoadCurrentProfessional before cache getByAuthId', ['uid' => $uid]);

        // Use cache service instead of a direct query
        $professional = $this->professionalCache->getByAuthId($uid);

        Log::info('LoadCurrentProfessional after cache getByAuthId', [
            'uid'   => $uid,
            'found' => (bool) $professional,
        ]);


        if (!$professional) {
            // Important: /api/bootstrap should create this row
            Log::info('LoadCurrentProfessional no professional for uid', ['uid' => $uid]);
            return response()->json([
                'message' => 'professional profile missing. Call /api/bootstrap first.'
            ], 403);
        }

        if (($professional->status ?? 'active') !== 'active') {
            Log::info('LoadCurrentProfessional suspended account', [
                'uid'   => $uid,
                'status'=> $professional->status ?? null,
            ]);

            return response()->json([
                'message' => 'Your account is suspended.'
            ], 403);
        }

        $request->attributes->set('professional', $professional);

        Log::info('LoadCurrentProfessional before next middleware');

        return $next($request);
    }
}
