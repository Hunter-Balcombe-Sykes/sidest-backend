<?php

use App\Http\Controllers\Api\Enterprise\EnterpriseController;
use Illuminate\Support\Facades\Route;

Route::prefix('enterprise')
    ->middleware(['supabase.jwt', 'throttle:enterprise'])
    ->group(function () {
        Route::get('/me', [EnterpriseController::class, 'show']);
        Route::post('/me', [EnterpriseController::class, 'store']);
        Route::patch('/me', [EnterpriseController::class, 'update']);
        Route::delete('/me', [EnterpriseController::class, 'destroy']);
    });
