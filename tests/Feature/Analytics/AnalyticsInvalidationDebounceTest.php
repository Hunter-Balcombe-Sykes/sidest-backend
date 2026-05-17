<?php

use App\Services\Cache\AnalyticsCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// CACHE-2: Verifies the public analytics ingest endpoints (pageview, click, cart event,
// section seen) throttle analytics-cache invalidation to once per 30-second window per
// professional. Without this, every storefront pageview busts the SWR cache for
// dashboard reads, defeating the cache entirely on active sites.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSiteVisitsTable();
    setupBlocksTable();
    setupLinkClicksTable();
    attachTestSchemas();
    Queue::fake();
    Cache::flush();

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

it('only invalidates the analytics cache once across rapid pageviews within the 30s debounce window', function () {
    $tenant = createBrandTenant('debounce-pv-'.Str::random(4));

    $cacheSvc = Mockery::mock(AnalyticsCacheService::class);
    $cacheSvc->shouldReceive('invalidateAnalytics')
        ->once()
        ->with($tenant->id);
    app()->instance(AnalyticsCacheService::class, $cacheSvc);

    // Five rapid pageviews from the same site — only the first should bump the cache.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/public/analytics/pageviews', [
            'site_id' => $tenant->site->id,
        ])->assertStatus(201);
    }
});

it('debounces invalidation across a mix of pageview, click, and cart-event ingest', function () {
    $tenant = createBrandTenant('debounce-mix-'.Str::random(4));
    $block = createLinkBlockFor($tenant);

    $cacheSvc = Mockery::mock(AnalyticsCacheService::class);
    $cacheSvc->shouldReceive('invalidateAnalytics')
        ->once()
        ->with($tenant->id);
    app()->instance(AnalyticsCacheService::class, $cacheSvc);

    $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
    ])->assertStatus(201);

    $this->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'visitor_id' => (string) Str::uuid(),
    ])->assertStatus(201);

    $this->postJson('/api/public/analytics/cart-events', [
        'site_id' => $tenant->site->id,
        'event_type' => 'cart_add',
    ])->assertStatus(201);
});

it('invalidates again once the debounce window has elapsed', function () {
    $tenant = createBrandTenant('debounce-window-'.Str::random(4));

    $cacheSvc = Mockery::mock(AnalyticsCacheService::class);
    $cacheSvc->shouldReceive('invalidateAnalytics')
        ->twice()
        ->with($tenant->id);
    app()->instance(AnalyticsCacheService::class, $cacheSvc);

    // First pageview — should bump (call 1).
    $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
    ])->assertStatus(201);

    // Second pageview still inside the 30s debounce window — must NOT bump.
    // If the controller skipped debounce, this would push the count to 2 and the
    // post-window pageview below would push it to 3, busting the ->twice() expectation.
    $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
    ])->assertStatus(201);

    // Simulate the debounce key expiring by clearing it directly — the array cache
    // driver has no time-travel and Carbon::setTestNow() doesn't affect its TTL.
    Cache::forget("analytics:ingest-debounce:{$tenant->id}");

    // Third pageview after window — should bump (call 2).
    $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenant->site->id,
    ])->assertStatus(201);
});

it('debounces invalidation independently per professional', function () {
    $tenantA = createBrandTenant('debounce-iso-a-'.Str::random(4));
    $tenantB = createBrandTenant('debounce-iso-b-'.Str::random(4));

    $cacheSvc = Mockery::mock(AnalyticsCacheService::class);
    $cacheSvc->shouldReceive('invalidateAnalytics')->once()->with($tenantA->id);
    $cacheSvc->shouldReceive('invalidateAnalytics')->once()->with($tenantB->id);
    app()->instance(AnalyticsCacheService::class, $cacheSvc);

    $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenantA->site->id,
    ])->assertStatus(201);

    $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenantB->site->id,
    ])->assertStatus(201);

    // Second pageview for tenantA inside the debounce window — should NOT bump again.
    $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $tenantA->site->id,
    ])->assertStatus(201);
});
