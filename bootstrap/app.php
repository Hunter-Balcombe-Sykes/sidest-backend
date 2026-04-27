<?php

use App\Http\Middleware\AddPublicCacheHeaders;
use App\Http\Middleware\Auth\EnsureSidestAdmin;
use App\Http\Middleware\Auth\EnsureSidestStaff;
use App\Http\Middleware\Auth\VerifyHydrogenApiKey;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Context\LoadCurrentProfessional;
use App\Http\Middleware\BrandFundingGate;
use App\Http\Middleware\FeatureGate;
use App\Http\Middleware\Logging\LogLeadRateLimits;
use App\Http\Middleware\RequirePlan;
use App\Http\Middleware\SecureHeaders;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\BackfillHourlyAnalytics::class,
        \App\Console\Commands\CompactHourlyAnalytics::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecureHeaders::class);

        // Apply public-cache headers to every API route. The middleware itself
        // only emits Cache-Control/Vary headers for the allow-listed public paths;
        // all other routes pass through untouched.
        $middleware->appendToGroup('api', AddPublicCacheHeaders::class);

        $middleware->alias([
            'supabase.jwt' => VerifySupabaseJwt::class,
            'current.pro' => LoadCurrentProfessional::class,
            'staff' => EnsureSidestStaff::class,
            'staff.admin' => EnsureSidestAdmin::class,
            'lead.log' => LogLeadRateLimits::class,
            'plan' => RequirePlan::class,
            'hydrogen.key' => VerifyHydrogenApiKey::class,
            'feature' => FeatureGate::class,
            'brand-funding-gate' => BrandFundingGate::class,
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
                \Illuminate\Support\Facades\Log::warning('Access denied', [
                    'path' => $request->path(),
                    'message' => $e->getMessage(),
                ]);

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
