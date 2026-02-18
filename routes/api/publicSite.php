<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use App\Http\Controllers\Api\PublicSite\AnalyticsController;
use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicMarketingPreferenceController;

$publicDomain = config('comet.public_domain');

// Public/Anon
Route::group([
    'domain' => '{subdomain}.' . $publicDomain,
    'where' => ['subdomain' => '[A-Za-z0-9-]+'],
    'prefix' => 'public',
], function () {

    // Show Site
    Route::get('/site', [PublicSiteController::class, 'show'])
        ->middleware('throttle:public-site');

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

// TOBIAS ADDED (NEEDS REVIEW)
// Header-based fallback for path-based frontend routing (e.g. /shloom).
// When the frontend cannot use subdomain DNS, it sends the subdomain
// via the X-Site-Subdomain header through the Next.js proxy.
Route::prefix('public')->group(function () {
    Route::get('/site', [PublicSiteController::class, 'showByHeader'])
        ->middleware('throttle:public-site');
});
