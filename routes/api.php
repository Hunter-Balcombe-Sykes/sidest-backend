<?php

use App\Http\Controllers\Api\Public\BootstrapController;
use App\Http\Controllers\Api\Public\PublicEmailUnsubscribeController;
use Illuminate\Support\Facades\Route;

// Ping
Route::get('/ping', fn () => response()->json(['pong' => true]));

// bootstrap uses ONLY JWT middleware
Route::middleware(['supabase.jwt'])->post('/bootstrap', [BootstrapController::class, 'bootstrap']);

// Split route files (keeps api.php tidy)
require __DIR__ . '/api/professional.php';
require __DIR__ . '/api/staff.php';
require __DIR__ . '/api/public.php';

Route::get('/public/unsubscribe/{token}', [PublicEmailUnsubscribeController::class, 'unsubscribe'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:public-site')
    ->name('public.unsubscribe');



