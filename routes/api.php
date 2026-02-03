<?php

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Controllers\Api\PublicSite\PublicEmailUnsubscribeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

// Ping
Route::get('/ping', fn () => response()->json(['pong' => true]));

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
Route::get('/ready', [HealthController::class, 'check']);

Route::get('/debug-db', function () {
    $start = microtime(true);

    try {
        Log::info('/api/debug-db before DB connect');

        DB::connection()->getPdo();
        $duration = microtime(true) - $start;

        Log::info('/api/debug-db after DB connect', [
            'ms' => $duration * 1000,
        ]);

        return response()->json([
            'ok'       => true,
            'duration' => $duration,
        ]);
    } catch (\Throwable $e) {
        Log::error('/api/debug-db error', ['message' => $e->getMessage()]);

        return response()->json([
            'ok'    => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});
