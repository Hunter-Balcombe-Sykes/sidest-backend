<?php

use App\Http\Middleware\AddPublicCacheHeaders;
use App\Http\Middleware\Auth\EnsureCometAdmin;
use App\Http\Middleware\Auth\EnsureCometStaff;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Context\LoadCurrentProfessional;
use App\Http\Middleware\Logging\LogLeadRateLimits;
use App\Http\Middleware\RequirePlan;
use App\Http\Middleware\SecureHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecureHeaders::class);

        $middleware->alias([
            'supabase.jwt' => VerifySupabaseJwt::class,
            'current.pro'  => LoadCurrentProfessional::class,
            'staff'        => EnsureCometStaff::class,
            'staff.admin'  => EnsureCometAdmin::class,
            'lead.log'     => LogLeadRateLimits::class,
            'api'          => AddPublicCacheHeaders::class,
            'plan'         => RequirePlan::class,
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

            // Preserve explicit response exceptions (e.g. throttle 429)
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            // Generic error handling
            $statusCode = 500;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                $statusCode = $e->getStatusCode();
            }

            // Log full exception for debugging even in production
            if ($statusCode >= 500) {
                \Illuminate\Support\Facades\Log::error('API Error', [
                    'exception' => $e,
                    'status' => $statusCode,
                ]);
            }

            // Don't expose internal errors in production
            $message = config('app.debug')
                ? $e->getMessage()
                : 'An error occurred';

            return response()->json([
                'message' => $message,
            ], $statusCode);
        });
    })->create();
