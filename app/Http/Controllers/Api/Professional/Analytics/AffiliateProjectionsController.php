<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Professional\Analytics\AffiliateProjectionsRequest;
use App\Http\Resources\Professional\Analytics\AffiliateProjectionsResource;
use App\Models\Commerce\BrandAffiliateRollup;
use App\Services\Analytics\AffiliateProjectionsService;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;

/**
 * GET /api/professional/affiliate/projections
 *
 * Returns straight-line annual + year-end forecasts, run-rate, momentum, YTD,
 * best-month, and engagement metrics for the authenticated affiliate.
 *
 * Caching: SWR via CacheLockService::rememberLocked, keyed only on professional_id
 * (not on query params — projections are absolute "as of now"). TTL is configurable
 * (`partna.commerce_analytics.projections_ttl_seconds`, default 300s). Invalidated
 * by AnalyticsCacheService::invalidateAnalytics() on every commerce write.
 *
 * Authorization: defense-in-depth — RLS on commerce.brand_affiliate_rollup blocks
 * cross-tenant reads at the DB layer, plus an explicit policy gate here for HTTP-layer 403s.
 */
class AffiliateProjectionsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly CacheLockService $cacheLock,
        private readonly AffiliateProjectionsService $projections,
    ) {}

    public function show(AffiliateProjectionsRequest $request): \Illuminate\Http\JsonResponse
    {
        $professional = $this->currentProfessional($request);

        // Defense-in-depth authorization. RLS will also block cross-tenant rollup reads,
        // but this gives a clean 403 at the HTTP edge instead of an empty 200.
        $skeleton = (new BrandAffiliateRollup)->forceFill([
            'affiliate_professional_id' => $professional->id,
        ]);
        $this->authorizeForUser($professional, 'viewProjections', $skeleton);

        // Form Request rules constrain this to in:14,30,60,90 or absent. input() works for both
        // real HTTP requests (validated by middleware) and unit tests that construct the request
        // directly without invoking the validator pipeline.
        $override = $request->input('window_days') !== null ? (int) $request->input('window_days') : null;

        $ttl = (int) config('partna.commerce_analytics.projections_ttl_seconds', 300);
        $cacheKey = CacheKeyGenerator::affiliateProjections((string) $professional->id, $override);

        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            $ttl,
            fn () => $this->projections->build($professional, $override),
        );

        return $this->success(
            (new AffiliateProjectionsResource($payload))->toArray($request),
        );
    }
}
