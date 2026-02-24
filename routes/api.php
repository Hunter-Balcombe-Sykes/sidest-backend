<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Controllers\Api\PublicSite\PublicEmailUnsubscribeController;
use App\Http\Controllers\Api\PublicSite\PublicBookingController;
use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use App\Http\Controllers\Api\Webhooks\SquareCatalogWebhookController;
use App\Http\Controllers\Api\Webhooks\FreshaCatalogWebhookController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;

// Ping
Route::get('/ping', fn () => response()->json(['pong' => true]));

// Square webhooks (no auth middleware)
Route::post('/webhooks/square', SquareCatalogWebhookController::class);
Route::post('/webhooks/square/catalog', SquareCatalogWebhookController::class);

// Fresha webhooks (no auth middleware)
Route::post('/webhooks/fresha', FreshaCatalogWebhookController::class);
Route::post('/webhooks/fresha/catalog', FreshaCatalogWebhookController::class);

// bootstrap uses ONLY JWT middleware
Route::middleware(['supabase.jwt'])->post('/bootstrap', [BootstrapController::class, 'bootstrap']);

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

Route::get('/ready', [HealthController::class, 'check']);
