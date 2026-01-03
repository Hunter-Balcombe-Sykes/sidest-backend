<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
        RateLimiter::for('public-site', function (Request $request) {
            $sub = $request->route('subdomain')
                ?? $request->input('subdomain')
                ?? explode('.', $request->getHost())[0]
                ?? 'unknown';

            return Limit::perMinute(300)->by("public-site:{$sub}|ip:{$request->ip()}");
        });

        RateLimiter::for('analytics', function (Request $request) {
            $sub = $request->route('subdomain')
                ?? $request->input('subdomain')
                ?? explode('.', $request->getHost())[0]
                ?? 'unknown';

            return [
                Limit::perMinute(1200)->by("analytics:{$sub}|ip:{$request->ip()}"),
                Limit::perHour(10000)->by("analytics:{$sub}|ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('leads', function (Request $request) {
            $sub = $request->route('subdomain')
                ?? $request->input('subdomain')
                ?? explode('.', $request->getHost())[0]
                ?? 'unknown';

            return [
                Limit::perMinute(5)->by("leads:{$sub}|ip:{$request->ip()}"),
                Limit::perDay(40)->by("leads:{$sub}|ip:{$request->ip()}"),
            ];
        });
    }

}
