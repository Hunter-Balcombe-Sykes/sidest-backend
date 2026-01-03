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
        // Handle API exceptions with JSON responses
        $exceptions->render(function (Throwable $e, Request $request) {
            // Only handle API routes
            if (! $request->is('api/*')) {
                return null;
            }

            // Validation errors (422)
            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }

            // Model not found (404)
            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'message' => 'Resource not found',
                ], 404);
            }

            // Not found (404)
            if ($e instanceof NotFoundHttpException) {
                return response()->json([
                    'message' => 'Endpoint not found',
                ], 404);
            }

            // Forbidden (403)
            if ($e instanceof AccessDeniedHttpException) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'Access denied',
                ], 403);
            }

            // Generic error handling
            $statusCode = method_exists($e, 'getStatusCode')
                ? $e->getStatusCode()
                : 500;

            // Don't expose internal errors in production
            $message = config('app.debug')
                ? $e->getMessage()
                : 'An error occurred';

            return response()->json([
                'message' => $message,
            ], $statusCode);
        });
    })->create();

