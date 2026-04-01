<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PublicSite\QrCodeController;
use App\Http\Controllers\Api\Shopify\ShopifyAppOAuthController;

Route::get('/', function () {
    return 'joshua hunter is awesome';
});

// Shopify App OAuth — install + callback
Route::get('/api/shopify/install', [ShopifyAppOAuthController::class, 'install'])
    ->middleware('throttle:60,1');

Route::get('/api/shopify/callback', [ShopifyAppOAuthController::class, 'callback'])
    ->middleware('throttle:60,1');

Route::get('/p/{qr_slug}.svg', [QrCodeController::class, 'svg'])
    ->where('qr_slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-site');

Route::get('/p/{qr_slug}', [QrCodeController::class, 'redirect'])
    ->where('qr_slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-site');
