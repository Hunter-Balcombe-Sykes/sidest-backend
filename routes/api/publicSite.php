<?php

use App\Http\Controllers\Api\PublicSite\PublicBookingController;
use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicEnquiryController;
use App\Http\Controllers\Api\PublicSite\PublicMarketingPreferenceController;
use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes in this file should be prefixed /v1/ once frontend is ready for the migration

// Fallback to 'partna.au' so a missing/typo'd PARTNA_PUBLIC_DOMAIN env doesn't
// silently produce an unmatched domain pattern that breaks every public route.
// AppServiceProvider::boot() additionally hard-fails the deploy in production
// if the config resolves to an empty string.
$publicDomain = config('partna.public_domain') ?: 'partna.au';

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

    // Analytics routes moved to the top-level group in routes/api.php (api host has no
    // site-subdomain to capture; Hydrogen storefronts proxy to dev-api.partna.au, which
    // the {subdomain}.partna.au pattern greedy-matched as subdomain=dev-api, overwriting
    // the payload subdomain in ResolvesPublicSiteSubdomain and 404'ing the site lookup).

    // Customer Leads
    Route::post('/customers', [PublicCustomerLeadController::class, 'store'])
        ->middleware(['lead.log', 'throttle:leads']);

    // Contact Section Enquiries
    Route::post('/enquiry', [PublicEnquiryController::class, 'submit'])
        ->middleware(['lead.log', 'throttle:leads']);

    Route::post('/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
        ->middleware('throttle:public-site');

    // Marketing Preferences
    Route::get('/marketing-preference', [PublicMarketingPreferenceController::class, 'show'])
        ->middleware('throttle:public-site');

    Route::post('/unsubscribe/{token}', [PublicMarketingPreferenceController::class, 'unsubscribe'])
        ->middleware('throttle:public-site');

    // Resubscribe via token was removed — tokens rotate on unsubscribe to block
    // email-link replay. Re-subscribing requires explicit opt-in via
    // POST /api/public/subscribe (PublicEmailSubscriptionController).
});
