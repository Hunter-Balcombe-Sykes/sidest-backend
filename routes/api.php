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

Route::get('/debug-themes', function () {
    $start = microtime(true);

    try {
        Log::info('/api/debug-themes before connect');

        // Measure connect
        DB::connection()->getPdo();

        Log::info('/api/debug-themes after connect');

        // Measure a simple SELECT against core.themes
        $themes = DB::table('themes')
            ->select('id', 'key', 'name')
            ->limit(3)
            ->get();

        Log::info('/api/debug-themes after query', [
            'count'    => $themes->count(),
        ]);

        return response()->json([
            'ok'         => true,
            'themes'     => $themes,
        ]);
    } catch (\Throwable $e) {
        Log::error('/api/debug-themes error', ['message' => $e->getMessage()]);
        return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
});

Route::get('/debug-db-pdo', function () {
    $start = microtime(true);

    try {
        Log::info('/api/debug-db-pdo before connect');

        // 1) Connect timing
        $connStart = microtime(true);
        $pdo = DB::connection()->getPdo();
        $connMs = (microtime(true) - $connStart) * 1000;

        Log::info('/api/debug-db-pdo after connect', [
            'connect_ms' => $connMs,
        ]);

        // 2) Super simple query timing
        $qStart = microtime(true);
        $stmt = $pdo->query('select 1 as one'); // no params, no schema
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
        $qMs = (microtime(true) - $qStart) * 1000;

        Log::info('/api/debug-db-pdo after query', [
            'query_ms' => $qMs,
            'row'      => $row,
        ]);

        return response()->json([
            'ok'         => true,
            'connect_ms' => $connMs,
            'query_ms'   => $qMs,
            'row'        => $row,
            'total_ms'   => (microtime(true) - $start) * 1000,
        ]);
    } catch (\Throwable $e) {
        Log::error('/api/debug-db-pdo error', [
            'message' => $e->getMessage(),
        ]);

        return response()->json([
            'ok'    => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});