<?php

namespace App\Http\Middleware\Context;

use App\Services\Cache\ProfessionalCacheService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoadCurrentProfessional
{
    public function __construct(
        private ProfessionalCacheService $professionalCache
    ) {}
    public function handle(Request $request, Closure $next): Response
    {
        $uid = $request->attributes->get('supabase_uid');
        if (!$uid) {
            return response()->json(['message' => 'Missing uid'], 401);
        }

        // Use cache service instead of a direct query
        $professional = $this->professionalCache->getByAuthId($uid);

        if (!$professional) {
            // Important: /api/bootstrap should create this row
            return response()->json([
                'message' => 'professional profile missing. Call /api/bootstrap first.'
            ], 403);
        }

        if (($professional->status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'Your account is suspended.'
            ], 403);
        }

        $request->attributes->set('professional', $professional);

        return $next($request);
    }
}
