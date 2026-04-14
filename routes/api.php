<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Controllers\Api\PublicSite\PublicConfigController;
use App\Http\Controllers\Api\PublicSite\PublicEmailUnsubscribeController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicBookingController;
use App\Http\Controllers\Api\PublicSite\PublicBrandAffiliateInviteController;
use App\Http\Controllers\Api\PublicSite\PublicOpenInviteController;
use App\Http\Controllers\Api\PublicSite\PublicSignupAvailabilityController;
use App\Http\Controllers\Api\PublicSite\PublicWaitlistController;
use App\Http\Controllers\Api\PublicSite\AnalyticsController;
use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use App\Http\Controllers\Api\PublicSite\PublicShopifyStorefrontController;
use App\Http\Controllers\Api\Webhooks\SquareCatalogWebhookController;
use App\Http\Controllers\Api\Webhooks\FreshaCatalogWebhookController;
use App\Http\Controllers\Api\Internal\HydrogenAffiliateController;
use App\Http\Controllers\Api\Internal\HydrogenAffiliateProductsController;
use App\Http\Controllers\Api\Internal\HydrogenBrandConfigController;
use App\Http\Controllers\Api\Internal\HydrogenBrandDesignController;
use App\Http\Controllers\Api\Webhooks\ShopifyAppUninstalledWebhookController;
use App\Http\Controllers\Api\Webhooks\ShopifyGdprWebhookController;
use App\Http\Controllers\Api\Webhooks\ShopifyOrdersUpdatedWebhookController;
use App\Http\Controllers\Api\Webhooks\ShopifyOrderWebhookController;
use App\Http\Controllers\Api\Webhooks\ShopifyShopUpdateWebhookController;
use App\Http\Controllers\Api\Webhooks\StripeConnectWebhookController;
use App\Http\Controllers\Api\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Shopify\ShopifyAppOAuthController;

// Ping
Route::get('/ping', fn () => response()->json(['pong' => true]));

// Shopify App OAuth (no auth — Shopify redirects here during install)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/shopify/install', [ShopifyAppOAuthController::class, 'install']);
    Route::get('/shopify/callback', [ShopifyAppOAuthController::class, 'callback']);
    Route::get('/shopify/setup-prefill', [ShopifyAppOAuthController::class, 'setupPrefill']);
});

// Webhooks (no auth middleware — signature validated in controller)
Route::middleware('throttle:webhooks')->group(function () {
    Route::post('/webhooks/square', SquareCatalogWebhookController::class);
    Route::post('/webhooks/square/catalog', SquareCatalogWebhookController::class);
    Route::post('/webhooks/fresha', FreshaCatalogWebhookController::class);
    Route::post('/webhooks/fresha/catalog', FreshaCatalogWebhookController::class);
    Route::post('/webhooks/stripe-connect', StripeConnectWebhookController::class);
    Route::post('/webhooks/stripe', StripeWebhookController::class);
    Route::post('/webhooks/shopify/orders', ShopifyOrderWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/orders-paid', ShopifyOrderWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/orders-updated', ShopifyOrdersUpdatedWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/app-uninstalled', ShopifyAppUninstalledWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/shop-update', ShopifyShopUpdateWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/gdpr/customers-data-request', [ShopifyGdprWebhookController::class, 'customersDataRequest'])
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/gdpr/customers-redact', [ShopifyGdprWebhookController::class, 'customersRedact'])
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/gdpr/shop-redact', [ShopifyGdprWebhookController::class, 'shopRedact'])
        ->middleware('throttle:shopify-webhooks');
});

// bootstrap uses ONLY JWT middleware
Route::middleware(['supabase.jwt', 'throttle:bootstrap'])->post('/bootstrap', [BootstrapController::class, 'bootstrap']);

// Split route files (keeps api.php tidy)
require __DIR__ . '/api/professional.php';
require __DIR__ . '/api/staff.php';
require __DIR__ . '/api/publicSite.php';

Route::get('/public/unsubscribe/{token}', [PublicEmailUnsubscribeController::class, 'unsubscribe'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:public-site')
    ->name('public.unsubscribe');

Route::get('/health', fn () => response()->json(['ok' => true]));

// Header-based fallback for path-based frontend routing (e.g. /shloom).
// When the frontend cannot use subdomain DNS, it sends the subdomain
// via the X-Site-Subdomain header through the Next.js proxy.
Route::get('/public/site-by-slug', [PublicSiteController::class, 'showByHeader'])
    ->middleware('throttle:public-site');

Route::get('/public/booking/config-by-slug', [PublicBookingController::class, 'config'])
    ->middleware('throttle:public-site');
Route::get('/public/booking/services-by-slug', [PublicBookingController::class, 'services'])
    ->middleware('throttle:public-site');
Route::post('/public/booking/availability-by-slug', [PublicBookingController::class, 'availability'])
    ->middleware('throttle:public-site');
Route::post('/public/booking/checkout-by-slug', [PublicBookingController::class, 'checkout'])
    ->middleware('throttle:public-site');
Route::get('/public/shopify/storefront-config', [PublicShopifyStorefrontController::class, 'storefrontConfig'])
    ->middleware('throttle:public-site');

// Static frontend config (social platform registry, etc). Aggressively cacheable.
// See docs/social-links.md for the social platforms contract.
Route::get('/public/config/social-platforms', [PublicConfigController::class, 'socialPlatforms'])
    ->middleware('throttle:public-site');

// Header/site-id based fallback for path-based frontend routing.
Route::post('/public/analytics/pageviews', [AnalyticsController::class, 'pageview'])
    ->middleware('throttle:analytics');
Route::post('/public/analytics/clicks', [AnalyticsController::class, 'click'])
    ->middleware('throttle:analytics');

Route::post('/public/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
    ->middleware('throttle:public-site');

Route::post('/public/signup/availability', [PublicSignupAvailabilityController::class, 'check'])
    ->middleware('throttle:public-site');
Route::post('/public/waitlist', [PublicWaitlistController::class, 'store'])
    ->middleware('throttle:waitlist');
Route::get('/public/brand-affiliate-invites/{token}', [PublicBrandAffiliateInviteController::class, 'show'])
    ->middleware('throttle:public-site');
Route::get('/public/join/{handle}', [PublicOpenInviteController::class, 'show'])
    ->where('handle', '[A-Za-z0-9][A-Za-z0-9_-]*')
    ->middleware('throttle:public-site');

Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
    ->middleware(['lead.log', 'throttle:leads']);

// Internal Hydrogen endpoints (server-to-server, API key auth)
Route::middleware(['hydrogen.key', 'throttle:hydrogen-internal'])->prefix('internal/hydrogen')->group(function () {
    Route::get('/brand-config', [HydrogenBrandConfigController::class, 'show']);
    Route::get('/brand-design/{slug}', [HydrogenBrandDesignController::class, 'show'])
        ->where('slug', '[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}');
    Route::get('/affiliate', [HydrogenAffiliateController::class, 'show']);
    Route::get('/affiliate-products', [HydrogenAffiliateProductsController::class, 'show']);
});

Route::get('/ready', [HealthController::class, 'check']);
