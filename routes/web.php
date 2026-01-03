<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PublicSite\QrCodeController;

Route::get('/', function () {
    return 'joshua hunter is awesome';
});

Route::get('/p/{qr_slug}.svg', [QrCodeController::class, 'svg'])
    ->where('qr_slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-site');

Route::get('/p/{qr_slug}', [QrCodeController::class, 'redirect'])
    ->where('qr_slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-site');
