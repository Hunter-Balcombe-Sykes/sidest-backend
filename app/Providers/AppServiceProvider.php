<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Public site endpoints (viewing sites, pages)
        RateLimiter:: for('public-site', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests.  Please try again later.',
                    ], 429);
                });
        });

        // Analytics endpoints (pageviews, clicks)
        RateLimiter:: for('analytics', function (Request $request) {
            return Limit::perMinute(120)
                ->by($request->ip());
        });

        // Customer lead submissions (form submissions)
        RateLimiter::for('leads', function (Request $request) {
            $subdomain = $request->route('subdomain') ?? 'unknown';

            return [
                // Per IP:  3 submissions per minute
                Limit::perMinute(3)
                    ->by($request->ip())
                    ->response(function () {
                        return response()->json([
                            'message' => 'Too many submissions. Please wait before trying again.',
                        ], 429);
                    }),

                // Per subdomain: 100 submissions per minute (prevent abuse)
                Limit::perMinute(100)
                    ->by($subdomain)
                    ->response(function () {
                        return response()->json([
                            'message' => 'This site is receiving too many submissions. Please try again later.',
                        ], 429);
                    }),
            ];
        });

        // Shopify webhook endpoints (keyed by shop domain, fallback to IP)
        RateLimiter::for('shopify-webhooks', function (Request $request) {
            $key = strtolower(trim((string) $request->header('x-shopify-shop-domain', '')));
            if ($key === '') {
                $key = $request->ip();
            }

            return Limit::perMinute(120)
                ->by($key)
                ->response(function () {
                    return response()->json(['message' => 'Too many webhook requests.'], 429);
                });
        });

        // API rate limit for authenticated users
        RateLimiter::for('api', function (Request $request) {
            $uid = $request->attributes->get('supabase_uid') ?? $request->ip();

            return Limit::perMinute(60)
                ->by($uid);
        });
    }
}
