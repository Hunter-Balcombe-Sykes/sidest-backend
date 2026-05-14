<?php

use App\Services\Cache\AnalyticsCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

// Verifies that exceptions thrown during analytics cache invalidation are
// reported to Nightwatch (report($e)) rather than silently swallowed.
//
// Before the fix: catch (Throwable) {} — completely silent, Nightwatch blind.
// After: catch (Throwable $e) { report($e); Log::warning(...); }

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSiteVisitsTable();
    setupLinkClicksTable();
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.cart_events (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        site_id TEXT NULL,
        event_type TEXT NULL,
        session_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        shopify_product_id TEXT NULL,
        quantity INTEGER NULL,
        occurred_at TEXT NULL,
        created_at TEXT NULL
    )');
});

it('reports the exception when analytics cache invalidation fails on pageview', function () {
    Exceptions::fake();

    $tenant = createBrandTenant('analytics-cache-pv-'.Str::random(4));

    $cacheSvc = Mockery::mock(AnalyticsCacheService::class);
    $cacheSvc->shouldReceive('invalidateAnalytics')
        ->andThrow(new \RuntimeException('Redis connection refused'));
    app()->instance(AnalyticsCacheService::class, $cacheSvc);

    $response = $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
    ]);

    // The pageview is still recorded — cache invalidation failure must not block the user
    $response->assertStatus(201);

    Exceptions::assertReported(\RuntimeException::class);
});

it('reports the exception when analytics cache invalidation fails on click', function () {
    Exceptions::fake();

    $tenant = createBrandTenant('analytics-cache-cl-'.Str::random(4));
    setupBlocksTable();
    $block = createLinkBlockFor($tenant);

    $cacheSvc = Mockery::mock(AnalyticsCacheService::class);
    $cacheSvc->shouldReceive('invalidateAnalytics')
        ->andThrow(new \RuntimeException('Redis connection refused'));
    app()->instance(AnalyticsCacheService::class, $cacheSvc);

    $response = $this->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
    ]);

    $response->assertStatus(201);

    Exceptions::assertReported(\RuntimeException::class);
});

it('reports the exception when analytics cache invalidation fails on cart event', function () {
    Exceptions::fake();

    $tenant = createBrandTenant('analytics-cache-ce-'.Str::random(4));

    $cacheSvc = Mockery::mock(AnalyticsCacheService::class);
    $cacheSvc->shouldReceive('invalidateAnalytics')
        ->andThrow(new \RuntimeException('Redis connection refused'));
    app()->instance(AnalyticsCacheService::class, $cacheSvc);

    $response = $this->postJson('/api/public/analytics/cart-events', [
        'site_id' => $tenant->site->id,
        'event_type' => 'cart_add',
    ]);

    $response->assertStatus(201);

    Exceptions::assertReported(\RuntimeException::class);
});
