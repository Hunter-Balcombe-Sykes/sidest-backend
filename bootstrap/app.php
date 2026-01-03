<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
        'supabase.jwt' => \App\Http\Middleware\Auth\VerifySupabaseJwt::class,
        'current.pro'  => \App\Http\Middleware\Context\LoadCurrentProfessional::class,
        'staff' => \App\Http\Middleware\Auth\EnsureCometStaff::class,
        'staff.admin' => \App\Http\Middleware\Auth\EnsureCometAdmin::class,
        'lead.log'     => \App\Http\Middleware\Logging\LogLeadRateLimits::class,
    ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

