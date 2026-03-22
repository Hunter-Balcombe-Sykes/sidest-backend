<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;

class StoreAnalyticsController extends ApiController
{
    /**
     * GET /store/analytics
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'This endpoint has been removed. Use /store/my-analytics/* endpoints.',
            'code' => 'STORE_ANALYTICS_ENDPOINT_GONE',
            'migrate_to' => [
                '/store/my-analytics/overview',
                '/store/my-analytics/products',
                '/store/my-analytics/commissions',
                '/store/my-analytics/payouts',
                '/store/my-analytics/timeseries',
            ],
        ], 410);
    }

    /**
     * GET /store/brand-analytics
     */
    public function brandIndex(): JsonResponse
    {
        return response()->json([
            'message' => 'This endpoint has been removed. Use /store/brand-analytics/* endpoints.',
            'code' => 'BRAND_ANALYTICS_ENDPOINT_GONE',
            'migrate_to' => [
                '/store/brand-analytics/overview',
                '/store/brand-analytics/influencers',
                '/store/brand-analytics/products',
                '/store/brand-analytics/commissions',
                '/store/brand-analytics/payouts',
                '/store/brand-analytics/timeseries',
            ],
        ], 410);
    }
}
