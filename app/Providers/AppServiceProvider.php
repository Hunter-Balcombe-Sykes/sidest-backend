<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

// V2: Bootstraps application-wide rate limiters for public, authenticated, webhook, staff, and internal API routes.
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
        $throttleEnabled = (bool) config('sidest.throttle.enabled', true);

        // Public site endpoints (viewing sites, pages)
        RateLimiter:: for('public-site', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests.  Please try again later.',
                    ], 429);
                });
        });

        // Analytics endpoints (pageviews, clicks)
        RateLimiter:: for('analytics', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(120)
                ->by($request->ip());
        });

        // Customer lead submissions (form submissions)
        RateLimiter::for('leads', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

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

        // Public waitlist submissions
        RateLimiter::for('waitlist', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return [Limit::none()];
            }

            $email = strtolower(trim((string) $request->input('email', '')));
            $emailKey = $email !== '' ? hash('sha256', $email) : 'unknown';

            return [
                Limit::perMinute(5)
                    ->by('waitlist:ip:'.$request->ip())
                    ->response(function () {
                        return response()->json([
                            'message' => 'Too many waitlist submissions. Please try again shortly.',
                        ], 429);
                    }),

                Limit::perHour(12)
                    ->by('waitlist:email:'.$emailKey)
                    ->response(function () {
                        return response()->json([
                            'message' => 'This email has been submitted recently. Please try again later.',
                        ], 429);
                    }),
            ];
        });

        // Shopify webhook endpoints (keyed by shop domain, fallback to IP)
        RateLimiter::for('shopify-webhooks', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

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

        // Affiliate selection write operations (create, delete, reorder)
        RateLimiter::for('affiliate-writes', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(60)
                ->by($request->attributes->get('supabase_uid') ?? $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many selection changes. Please try again later.',
                    ], 429);
                });
        });

        // Brand catalog write operations (metafield updates, collection management)
        RateLimiter::for('brand-catalog-writes', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(30)
                ->by($request->attributes->get('supabase_uid') ?? $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many catalog changes. Please try again later.',
                    ], 429);
                });
        });

        // Authenticated professional routes
        RateLimiter::for('authenticated', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(300)
                ->by($request->attributes->get('supabase_uid') ?? $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
                    ], 429);
                });
        });

        // Staff panel routes
        RateLimiter::for('staff', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(300)
                ->by($request->attributes->get('supabase_uid') ?? $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
                    ], 429);
                });
        });

        // Webhook endpoints (Square, Fresha, Stripe Connect)
        RateLimiter::for('webhooks', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(200)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many webhook requests.',
                    ], 429);
                });
        });

        // Account bootstrap (creation)
        RateLimiter::for('bootstrap', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(5)
                ->by($request->attributes->get('supabase_uid') ?? $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many account creation attempts. Please try again later.',
                    ], 429);
                });
        });

        // Internal Hydrogen endpoints (server-to-server)
        RateLimiter::for('hydrogen-internal', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(120)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests.',
                    ], 429);
                });
        });

        // Public plans listing
        RateLimiter::for('plans', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(30)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
                    ], 429);
                });
        });
    }
}
