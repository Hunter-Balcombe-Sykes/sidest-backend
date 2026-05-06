<?php

namespace App\Providers;

use App\Listeners\RecordScheduledTaskHeartbeat;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Policies\IntegrationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyAdminClient::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyBudgetTracker::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyCostTracker::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyMetrics::class);
        $this->app->singleton(\App\Services\Shopify\Client\ShopifyBulkOperationLock::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ProfessionalIntegration::class, IntegrationPolicy::class);
        Gate::policy(\App\Models\Core\Professional\Customer::class, \App\Policies\CustomerPolicy::class);
        Gate::policy(\App\Models\Core\Site\Site::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Core\Site\Block::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Core\Site\SiteMedia::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Core\Site\SiteSubdomainAlias::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Core\Site\Enquiry::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Analytics\LeadSubmission::class, \App\Policies\SitePolicy::class);
        Gate::policy(\App\Models\Retail\CommissionPayout::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Retail\CommissionLedgerEntry::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Retail\CommissionMovement::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\Order::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\OrderItem::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\BrandAffiliateRollup::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Retail\BrandCommissionTopup::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Core\Professional\BrandPartnerLink::class, \App\Policies\BrandPartnerLinkPolicy::class);
        Gate::policy(\App\Models\Core\Professional\BrandPartnerLinkEvent::class, \App\Policies\BrandPartnerLinkPolicy::class);
        Gate::policy(\App\Models\Core\Professional\BrandAffiliateInvite::class, \App\Policies\BrandPartnerLinkPolicy::class);
        Gate::policy(\App\Models\Core\Professional\Service::class, \App\Policies\ServicePolicy::class);
        Gate::policy(\App\Models\Core\Professional\ServiceCategory::class, \App\Policies\ServicePolicy::class);
        Gate::policy(\App\Models\Retail\BrandStoreSettings::class, \App\Policies\BrandResourcePolicy::class);
        Gate::policy(\App\Models\Core\Professional\BrandProfile::class, \App\Policies\BrandResourcePolicy::class);
        Gate::policy(\App\Models\Retail\BrandTeamMembership::class, \App\Policies\BrandResourcePolicy::class);
        Gate::policy(\App\Models\Core\Professional\Professional::class, \App\Policies\ProfessionalSelfPolicy::class);
        Gate::policy(\App\Models\Core\Professional\ProfessionalConfirmationPreference::class, \App\Policies\ProfessionalSelfPolicy::class);
        Gate::policy(\App\Models\Core\Professional\WalletCurrencySwitchAudit::class, \App\Policies\ProfessionalSelfPolicy::class);
        Gate::policy(\App\Models\Core\Professional\ProfessionalDeletionAuditEntry::class, \App\Policies\ProfessionalSelfPolicy::class);
        Gate::policy(\App\Models\Billing\Subscription::class, \App\Policies\SubscriptionPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\Notification::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\NotificationEmailPreference::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\NotificationEmailPolicy::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\NotificationReceipt::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Notifications\EmailSubscription::class, \App\Policies\NotificationPolicy::class);
        Gate::policy(\App\Models\Core\Gdpr\GdprRequest::class, \App\Policies\GdprPolicy::class);
        Gate::policy(\App\Models\Core\Gdpr\DataExportAudit::class, \App\Policies\GdprPolicy::class);
        Gate::policy(\App\Models\Commerce\AffiliateProductSelection::class, \App\Policies\AffiliateProductPolicy::class);

        // Refuse to boot in production with throttling disabled — a misconfigured
        // SIDEST_THROTTLE_ENABLED=false would silently strip all rate limiting.
        if (app()->isProduction() && ! (bool) config('sidest.throttle.enabled', true)) {
            throw new \RuntimeException('SIDEST_THROTTLE_ENABLED must not be false in production.');
        }

        $this->configureRateLimiting();

        // Scheduler heartbeat — feeds GET /api/health/scheduler so a stopped cron
        // runner becomes visible. See RecordScheduledTaskHeartbeat for rationale.
        Event::listen(ScheduledTaskStarting::class, RecordScheduledTaskHeartbeat::class);

        // Strict-mode N+1 trap: throw on unloaded relation access outside production
        // so tests/local catch lazy loading instead of leaking slow queries to prod.
        Model::preventLazyLoading(! app()->isProduction());
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        $throttleEnabled = (bool) config('sidest.throttle.enabled', true);

        // Health-check and ping endpoints
        RateLimiter::for('health-check', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->ip());
        });

        // Public site endpoints (viewing sites, pages)
        RateLimiter::for('public-site', function (Request $request) use ($throttleEnabled) {
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

        // Booking checkout — tighter limit because checkout hits Stripe/Square synchronously
        RateLimiter::for('booking-checkout', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many booking attempts. Please try again shortly.',
                    ], 429);
                });
        });

        // Analytics endpoints (pageviews, clicks)
        RateLimiter::for('analytics', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            return Limit::perMinute(120)
                ->by($request->ip());
        });

        // Per-link click cap — secondary defense against sustained single-link spam
        RateLimiter::for('analytics-click', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }

            $blockId = $request->input('block_id', 'unknown');

            return Limit::perMinute(5)
                ->by($request->ip().':click:'.$blockId);
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

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on affiliate-writes route — JWT middleware not applied');

            return Limit::perMinute(60)
                ->by($uid)
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

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on brand-catalog-writes route — JWT middleware not applied');

            return Limit::perMinute(30)
                ->by($uid)
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

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on authenticated route — JWT middleware not applied');

            return Limit::perMinute(300)
                ->by($uid)
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

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on staff route — JWT middleware not applied');

            return Limit::perMinute(300)
                ->by($uid)
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

            $uid = $request->attributes->get('supabase_uid')
                ?? throw new \RuntimeException('supabase_uid missing on bootstrap route — JWT middleware not applied');

            return Limit::perMinute(5)
                ->by($uid)
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
