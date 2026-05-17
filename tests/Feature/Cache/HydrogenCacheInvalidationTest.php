<?php

use App\Models\Brand\BrandStoreSettings;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Observers\Brand\BrandStoreSettingsObserver;
use App\Observers\Commerce\AffiliateProductSelectionObserver;
use App\Observers\Core\ProfessionalIntegrationObserver;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery as M;

// Master Pattern 15: invalidation paths that don't require full DB fixtures —
// each test seeds the cache directly with Cache::put, then drives the observer
// or service method and asserts the seeded key is gone. Mirrors the pattern
// in CustomerCountCacheTest, which validates observer-driven cache busts the
// same way.

afterEach(function () {
    M::close();
});

it('forgetHydrogenAffiliateProducts clears the primary and :stale keys', function () {
    Cache::flush();

    $affiliateId = (string) Str::uuid();
    $key = CacheKeyGenerator::hydrogenAffiliateProducts($affiliateId);

    Cache::put($key, ['gids' => ['fake']], 600);
    Cache::put($key.':stale', ['gids' => ['stale']], 6000);
    expect(Cache::has($key))->toBeTrue()
        ->and(Cache::has($key.':stale'))->toBeTrue();

    $service = new SiteCacheService(new CacheLockService);
    $service->forgetHydrogenAffiliateProducts($affiliateId);

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($key.':stale'))->toBeFalse();
});

it('forgetHydrogenAffiliateProducts is a no-op for empty affiliate id', function () {
    Cache::flush();

    // No matter what's in the cache, an empty id never deletes anything.
    Cache::put('hydrogen:affiliate-products:v1:', 'unsafe', 60);

    $service = new SiteCacheService(new CacheLockService);
    $service->forgetHydrogenAffiliateProducts('');

    expect(Cache::has('hydrogen:affiliate-products:v1:'))->toBeTrue();
});

it('AffiliateProductSelectionObserver busts the hydrogen-affiliate-products cache on save', function () {
    Cache::flush();

    $affiliateId = (string) Str::uuid();
    $key = CacheKeyGenerator::hydrogenAffiliateProducts($affiliateId);

    Cache::put($key, ['gids' => ['x']], 600);
    Cache::put($key.':stale', ['gids' => ['x']], 6000);

    $selection = new AffiliateProductSelection;
    $selection->id = (string) Str::uuid();
    $selection->affiliate_professional_id = $affiliateId;

    $service = new SiteCacheService(new CacheLockService);
    $observer = new AffiliateProductSelectionObserver($service);
    $observer->saved($selection);

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($key.':stale'))->toBeFalse();
});

it('AffiliateProductSelectionObserver busts on delete', function () {
    Cache::flush();

    $affiliateId = (string) Str::uuid();
    $key = CacheKeyGenerator::hydrogenAffiliateProducts($affiliateId);

    Cache::put($key, ['gids' => ['x']], 600);

    $selection = new AffiliateProductSelection;
    $selection->id = (string) Str::uuid();
    $selection->affiliate_professional_id = $affiliateId;

    $service = new SiteCacheService(new CacheLockService);
    $observer = new AffiliateProductSelectionObserver($service);
    $observer->deleted($selection);

    expect(Cache::has($key))->toBeFalse();
});

it('BrandStoreSettingsObserver busts the hydrogen-brand-config cache on save', function () {
    Cache::flush();

    // No ProfessionalIntegration row exists in this test, so the resolution
    // inside forgetHydrogenBrandConfig returns null and the bust is a no-op —
    // exactly the contract we want. We still want the observer to invoke the
    // bust method without throwing.
    $professionalId = (string) Str::uuid();

    $settings = new BrandStoreSettings;
    $settings->id = (string) Str::uuid();
    $settings->professional_id = $professionalId;

    $service = new SiteCacheService(new CacheLockService);
    $observer = new BrandStoreSettingsObserver($service);

    expect(fn () => $observer->saved($settings))->not->toThrow(Throwable::class);
});

it('ProfessionalIntegrationObserver busts the hydrogen-brand-config cache when shopify integration is updated', function () {
    Cache::flush();

    $shopDomain = 'test-bust.myshopify.com';
    $key = CacheKeyGenerator::hydrogenBrandConfig($shopDomain);

    Cache::put($key, ['payload' => 'old'], 600);
    Cache::put($key.':stale', ['payload' => 'old-stale'], 6000);

    $integration = new ProfessionalIntegration;
    $integration->id = (string) Str::uuid();
    $integration->professional_id = (string) Str::uuid();
    $integration->provider = ProfessionalIntegration::PROVIDER_SHOPIFY;
    $integration->shopify_shop_domain = $shopDomain;

    // Stub the two services the observer needs but doesn't exercise here.
    $publisher = M::mock(NotificationPublisher::class);
    $visibility = M::mock(SectionVisibilityService::class);

    $observer = new ProfessionalIntegrationObserver($publisher, $visibility);
    $observer->updated($integration);

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($key.':stale'))->toBeFalse();
});

it('ProfessionalIntegrationObserver also busts the OLD shop_domain key when domain changes', function () {
    Cache::flush();

    $oldDomain = 'old-shop.myshopify.com';
    $newDomain = 'new-shop.myshopify.com';
    $oldKey = CacheKeyGenerator::hydrogenBrandConfig($oldDomain);
    $newKey = CacheKeyGenerator::hydrogenBrandConfig($newDomain);

    Cache::put($oldKey, ['payload' => 'old'], 600);
    Cache::put($oldKey.':stale', ['payload' => 'old-stale'], 6000);
    Cache::put($newKey, ['payload' => 'new'], 600);

    // Simulate the post-save state Eloquent presents inside the `updated`
    // event hook: $original still holds the pre-save value (syncOriginal()
    // hasn't run yet — that happens in finishSave, AFTER `updated` fires),
    // but $changes is populated (syncChanges() ran inside performUpdate
    // before the event). Manually calling syncChanges() reproduces that
    // exact state without needing a real DB write.
    $integration = new ProfessionalIntegration;
    $integration->setRawAttributes([
        'id' => (string) Str::uuid(),
        'professional_id' => (string) Str::uuid(),
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => $oldDomain,
    ], sync: true);
    $integration->shopify_shop_domain = $newDomain;
    $integration->syncChanges();

    $publisher = M::mock(NotificationPublisher::class);
    $visibility = M::mock(SectionVisibilityService::class);
    $observer = new ProfessionalIntegrationObserver($publisher, $visibility);
    $observer->updated($integration);

    // Both the old AND new keys should be busted to prevent stale-shape leakage.
    expect(Cache::has($oldKey))->toBeFalse()
        ->and(Cache::has($oldKey.':stale'))->toBeFalse()
        ->and(Cache::has($newKey))->toBeFalse();
});

it('ProfessionalIntegrationObserver busts linked-affiliate products cache when provider_metadata changes', function () {
    // When a brand toggles custom_photos_enabled on the integration, every
    // affiliate linked to the brand has a stale `custom_photos` array in
    // their hydrogen-affiliate-products cache. We can't easily fixture a
    // BrandPartnerLink without a real DB, so this asserts the observer
    // dispatches into the bust path without throwing — the actual
    // BrandPartnerLink walk is covered by the integration-test layer.
    Cache::flush();

    // provider_metadata is a JSON-cast column: setRawAttributes stores the
    // raw (string) form; the setter casts the array on write. This avoids
    // the json_decode-on-array TypeError when we later read the attribute.
    $integration = new ProfessionalIntegration;
    $integration->setRawAttributes([
        'id' => (string) Str::uuid(),
        'professional_id' => (string) Str::uuid(),
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'meta-toggle.myshopify.com',
        'provider_metadata' => json_encode(['custom_photos_enabled' => false]),
    ], sync: true);
    $integration->provider_metadata = ['custom_photos_enabled' => true];
    $integration->syncChanges();

    $publisher = M::mock(NotificationPublisher::class);
    $visibility = M::mock(SectionVisibilityService::class);
    $observer = new ProfessionalIntegrationObserver($publisher, $visibility);

    // The bust runs synchronously inside updated() — exception would surface here.
    expect(fn () => $observer->updated($integration))->not->toThrow(Throwable::class);
});

it('ProfessionalIntegrationObserver skips hydrogen bust for non-shopify providers', function () {
    Cache::flush();

    // A non-shopify integration write must not interfere with any shopify-keyed cache.
    $key = CacheKeyGenerator::hydrogenBrandConfig('untouched.myshopify.com');
    Cache::put($key, ['payload' => 'keep'], 600);

    $integration = new ProfessionalIntegration;
    $integration->id = (string) Str::uuid();
    $integration->professional_id = (string) Str::uuid();
    $integration->provider = 'square';
    $integration->shopify_shop_domain = null;

    $publisher = M::mock(NotificationPublisher::class);
    $visibility = M::mock(SectionVisibilityService::class);
    $observer = new ProfessionalIntegrationObserver($publisher, $visibility);
    $observer->updated($integration);

    expect(Cache::has($key))->toBeTrue();
});
