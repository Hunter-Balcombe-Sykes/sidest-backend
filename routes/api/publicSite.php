<?php

use App\Http\Controllers\Api\PublicSite\AnalyticsController;
use App\Http\Controllers\Api\PublicSite\PublicBookingController;
use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicMarketingPreferenceController;
use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes in this file should be prefixed /v1/ once frontend is ready for the migration

$publicDomain = config('sidest.public_domain');

// Public/Anon
Route::group([
    'domain' => '{subdomain}.'.$publicDomain,
    'where' => ['subdomain' => '[A-Za-z0-9-]+'],
    'prefix' => 'public',
], function () {

    // Show Site
    Route::get('/site', [PublicSiteController::class, 'show'])
        ->middleware('throttle:public-site');

    // Public booking flow — gated behind smart_booking feature flag
    Route::middleware('feature:smart_booking')->group(function () {
        Route::get('/booking/config', [PublicBookingController::class, 'config'])
            ->middleware('throttle:public-site');
        Route::get('/booking/services', [PublicBookingController::class, 'services'])
            ->middleware('throttle:public-site');
        Route::post('/booking/availability', [PublicBookingController::class, 'availability'])
            ->middleware('throttle:public-site');
        Route::post('/booking/checkout', [PublicBookingController::class, 'checkout'])
            ->middleware('throttle:booking-checkout');
    });

    // Page View Analytics
    Route::post('/analytics/pageviews', [AnalyticsController::class, 'pageview'])
        ->middleware('throttle:analytics');

    // Click Analytics
    Route::post('/analytics/clicks', [AnalyticsController::class, 'click'])
        ->middleware('throttle:analytics');

    // Customer Leads
    Route::post('/customers', [PublicCustomerLeadController::class, 'store'])
        ->middleware(['lead.log', 'throttle:leads']);

    Route::post('/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
        ->middleware('throttle:public-site');

    // Marketing Preferences
    Route::get('/marketing-preference', [PublicMarketingPreferenceController::class, 'show'])
        ->middleware('throttle:public-site');

    Route::post('/unsubscribe/{token}', [PublicMarketingPreferenceController::class, 'unsubscribe'])
        ->middleware('throttle:public-site');

    Route::post('/resubscribe/{token}', [PublicMarketingPreferenceController::class, 'resubscribe'])
        ->middleware('throttle:public-site');
});
