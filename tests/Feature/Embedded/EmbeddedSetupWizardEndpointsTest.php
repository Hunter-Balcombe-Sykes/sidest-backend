<?php

use App\Http\Controllers\Api\Internal\EmbeddedSetupController;
use App\Http\Requests\Api\Internal\Embedded\SaveBusinessDetailsRequest;
use App\Http\Requests\Api\Internal\Embedded\SaveIdentityRequest;
use App\Http\Requests\Api\Internal\Embedded\SetupDomainRequest;
use App\Http\Requests\Api\Internal\Embedded\UpdateSettingRequest;
use App\Jobs\Cloudflare\ProvisionBrandDnsJob;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\BrandStatusService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

// EmbeddedSetupController wizard endpoint sweep. Each test exercises one
// controller method end-to-end against the in-memory SQLite mirror, asserting
// the DB-level effect rather than the response envelope. Side-effects from
// BrandStatusService::sync() and ProfessionalCacheService::invalidateProfessional()
// are mocked away — those have their own coverage; here we want the controller's
// own behavior under test in isolation.

beforeEach(function () {
    Cache::flush();
    Bus::fake();
    Queue::fake();

    setupProfessionalsTable();
    setupSitesTable();
    setupProfessionalIntegrationsTable();
    setupBrandStoreSettingsTable();
    setupBrandProfilesTable();

    attachTestSchemas();

    // setupBrandProfilesTable() omits a few columns the wizard writes — extend it
    // here without disturbing other tests that depend on the shared helper.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles_ext (id TEXT)'); // marker
    $cols = DB::connection('pgsql')->select("PRAGMA table_info('brand.brand_profiles')");
    $names = collect($cols)->pluck('name')->all();
    foreach (['legal_business_name', 'abn', 'business_type', 'industries'] as $col) {
        if (! in_array($col, $names, true)) {
            $type = $col === 'industries' ? 'TEXT' : 'TEXT';
            DB::connection('pgsql')->statement("ALTER TABLE brand.brand_profiles ADD COLUMN {$col} {$type}");
        }
    }

    // No-op the cross-cutting services that have their own coverage.
    $statusMock = mock(BrandStatusService::class);
    $statusMock->shouldReceive('sync')->andReturnNull();
    $statusMock->shouldReceive('determine')->andReturn(\App\Enums\BrandStatus::Onboarding);

    $cacheMock = mock(ProfessionalCacheService::class);
    $cacheMock->shouldReceive('invalidateProfessional')->andReturnNull();

    $this->controller = app(EmbeddedSetupController::class);
    $this->brandId = (string) Str::uuid();
    $this->shopDomain = 'shop.myshopify.com';

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $this->brandId,
        'display_name' => 'Original Name',
        'primary_email' => 'old@example.test',
        'phone' => '111',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $this->siteId,
        'professional_id' => $this->brandId,
        'subdomain' => 'test-brand',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
});

function attachEmbeddedAttr($request, string $brandId): void
{
    $request->attributes->set('embedded_professional_id', $brandId);
}

// ── saveIdentity ─────────────────────────────────────────────────────────────

it('saveIdentity updates only the supplied professional and brand-profile fields', function () {
    $request = SaveIdentityRequest::create('/internal/embedded/brand-identity', 'POST', [
        'name' => 'New Name',
        'contact_email' => 'new@example.test',
        'website_url' => 'https://example.test',
    ]);
    attachEmbeddedAttr($request, $this->brandId);
    $request->setValidator(app('validator')->make($request->all(), $request->rules()));

    $response = $this->controller->saveIdentity($request);

    expect($response->getStatusCode())->toBe(200);

    $pro = DB::connection('pgsql')->table('core.professionals')->where('id', $this->brandId)->first();
    expect($pro->display_name)->toBe('New Name');
    expect($pro->primary_email)->toBe('new@example.test');
    expect($pro->phone)->toBe('111'); // unchanged

    $bp = DB::connection('pgsql')->table('brand.brand_profiles')->where('professional_id', $this->brandId)->first();
    expect($bp->business_website)->toBe('https://example.test');
});

// ── saveBusinessDetails ──────────────────────────────────────────────────────

it('saveBusinessDetails persists ABN/business_type/industries to brand_profile', function () {
    $request = SaveBusinessDetailsRequest::create('/internal/embedded/brand-details', 'POST', [
        'legal_business_name' => 'Acme Pty Ltd',
        'abn' => '12345678901',
        'business_type' => 'company',
        'industries' => ['beauty_products', 'wellness'],
    ]);
    attachEmbeddedAttr($request, $this->brandId);
    $request->setValidator(app('validator')->make($request->all(), $request->rules()));

    $response = $this->controller->saveBusinessDetails($request);

    expect($response->getStatusCode())->toBe(200);

    $bp = DB::connection('pgsql')->table('brand.brand_profiles')->where('professional_id', $this->brandId)->first();
    expect($bp->legal_business_name)->toBe('Acme Pty Ltd');
    expect($bp->abn)->toBe('12345678901');
    expect($bp->business_type)->toBe('company');
    // industries is JSON-encoded via the model's array cast.
    expect(json_decode($bp->industries, true))->toBe(['beauty_products', 'wellness']);
});

// ── updateSetting (two key paths) ────────────────────────────────────────────

it('updateSetting writes default_commission_rate to brand_store_settings', function () {
    $request = UpdateSettingRequest::create('/internal/embedded/brand-settings', 'PATCH', [
        'key' => 'default_commission_rate',
        'value' => '12.5',
    ]);
    attachEmbeddedAttr($request, $this->brandId);
    $request->setValidator(app('validator')->make($request->all(), $request->rules()));

    $response = $this->controller->updateSetting($request);

    expect($response->getStatusCode())->toBe(200);

    $settings = BrandStoreSettings::where('professional_id', $this->brandId)->first();
    expect((float) $settings->default_commission_rate)->toBe(12.5);
});

it('updateSetting routes setup_complete to brand_profiles (not brand_store_settings)', function () {
    $request = UpdateSettingRequest::create('/internal/embedded/brand-settings', 'PATCH', [
        'key' => 'setup_complete',
        'value' => 'true',
    ]);
    attachEmbeddedAttr($request, $this->brandId);
    $request->setValidator(app('validator')->make($request->all(), $request->rules()));

    $response = $this->controller->updateSetting($request);

    expect($response->getStatusCode())->toBe(200);

    $bp = DB::connection('pgsql')->table('brand.brand_profiles')->where('professional_id', $this->brandId)->first();
    expect((int) $bp->setup_complete)->toBe(1);

    // brand_store_settings should NOT have been touched.
    $bss = DB::connection('pgsql')->table('brand.brand_store_settings')->where('professional_id', $this->brandId)->first();
    expect($bss)->toBeNull();
});

// ── confirmHydrogenInstall ───────────────────────────────────────────────────

it('confirmHydrogenInstall flips hydrogen_install_confirmed to true', function () {
    $request = \Illuminate\Http\Request::create('/internal/embedded/confirm-hydrogen', 'POST');
    attachEmbeddedAttr($request, $this->brandId);

    $response = $this->controller->confirmHydrogenInstall($request);

    expect($response->getStatusCode())->toBe(200);

    $settings = BrandStoreSettings::where('professional_id', $this->brandId)->first();
    expect((bool) $settings->hydrogen_install_confirmed)->toBeTrue();
});

// ── setupDomain (debounce + dispatch) ────────────────────────────────────────

it('setupDomain dispatches ProvisionBrandDnsJob exactly once when called twice within the debounce window', function () {
    // subdomain is validated for format but the controller derives the actual
    // CNAME hostname from the brand's site record — request input is never trusted.
    $payload = [
        'oxygen_storefront_id' => 'gid://shopify/HydrogenStorefront/123',
        'subdomain' => 'test-brand',
    ];

    // First call — debounce key absent → dispatch.
    $req1 = SetupDomainRequest::create('/internal/embedded/domain/setup', 'POST', $payload);
    attachEmbeddedAttr($req1, $this->brandId);
    $req1->setValidator(app('validator')->make($req1->all(), $req1->rules()));
    $this->controller->setupDomain($req1);

    // Second call — debounce key still present → no dispatch.
    $req2 = SetupDomainRequest::create('/internal/embedded/domain/setup', 'POST', $payload);
    attachEmbeddedAttr($req2, $this->brandId);
    $req2->setValidator(app('validator')->make($req2->all(), $req2->rules()));
    $this->controller->setupDomain($req2);

    Bus::assertDispatchedTimes(ProvisionBrandDnsJob::class, 1);

    // Both calls also persist the storefront_id; verify it landed.
    $settings = BrandStoreSettings::where('professional_id', $this->brandId)->first();
    expect($settings->oxygen_storefront_id)->toBe('gid://shopify/HydrogenStorefront/123');
});

// ── brandProfile (auto-heal Hydrogen flag) ───────────────────────────────────

it('brandProfile auto-heals hydrogen_install_confirmed when storefront is live', function () {
    // Pre-seed BSS with hydrogen NOT confirmed.
    BrandStoreSettings::create([
        'professional_id' => $this->brandId,
        'hydrogen_install_confirmed' => false,
    ]);

    // Pre-warm the storefront-status cache to 'live' so we don't make real HTTP.
    Cache::put(
        \App\Services\Cache\CacheKeyGenerator::brandStorefrontStatus($this->brandId),
        'live',
        60,
    );

    $request = \Illuminate\Http\Request::create('/internal/embedded/brand-profile', 'GET');
    attachEmbeddedAttr($request, $this->brandId);

    $response = $this->controller->brandProfile($request);
    $data = json_decode($response->getContent(), true);

    expect($data['hydrogen_confirmed'])->toBeTrue();
    expect($data['storefront_status'])->toBe('live');

    // And the auto-heal persisted to the row.
    $settings = BrandStoreSettings::where('professional_id', $this->brandId)->first();
    expect((bool) $settings->hydrogen_install_confirmed)->toBeTrue();
});

// ── embeddedProducts (catalog read + filter to active) ───────────────────────

it('embeddedProducts returns only products with metafields.active=true', function () {
    // Mock the catalog service BEFORE re-resolving the controller so DI picks
    // up the mock (the beforeEach-resolved controller already has the real one).
    $catalog = mock(BrandCatalogService::class);
    $catalog->shouldReceive('fetchBrandCatalog')->andReturn([
        ['gid' => 'p1', 'title' => 'Active 1', 'metafields' => ['active' => true, 'commission_override' => 0.20]],
        ['gid' => 'p2', 'title' => 'Inactive', 'metafields' => ['active' => false]],
        ['gid' => 'p3', 'title' => 'Active 2', 'metafields' => ['active' => true], 'featured_image' => ['url' => 'https://cdn.test/p3.jpg']],
    ]);
    $controller = app(EmbeddedSetupController::class);

    BrandStoreSettings::create([
        'professional_id' => $this->brandId,
        'default_commission_rate' => 15.0,
    ]);

    $request = \Illuminate\Http\Request::create('/internal/embedded/products', 'GET');
    attachEmbeddedAttr($request, $this->brandId);

    $response = $controller->embeddedProducts($request);
    $data = json_decode($response->getContent(), true);

    expect($data['products'])->toHaveCount(2);
    expect(array_column($data['products'], 'id'))->toBe(['p1', 'p3']);
    expect($data['products'][0]['commission_rate'])->toBe(0.20);
    expect($data['products'][1]['image_url'])->toBe('https://cdn.test/p3.jpg');
    expect((float) $data['default_commission_rate'])->toBe(15.0);
});

it('embeddedProducts returns an empty list when the catalog fetch throws', function () {
    $catalog = mock(BrandCatalogService::class);
    $catalog->shouldReceive('fetchBrandCatalog')->andThrow(new \RuntimeException('Shopify timeout'));
    $controller = app(EmbeddedSetupController::class);

    $request = \Illuminate\Http\Request::create('/internal/embedded/products', 'GET');
    attachEmbeddedAttr($request, $this->brandId);

    $response = $controller->embeddedProducts($request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true);
    expect($data['products'])->toBe([]);
});

// ── domainStatus / deployNow guard ───────────────────────────────────────────

it('deployNow returns 400 when no oxygen_deployment_token has been saved', function () {
    BrandStoreSettings::create(['professional_id' => $this->brandId]);

    $request = \Illuminate\Http\Request::create('/internal/embedded/deploy', 'POST');
    attachEmbeddedAttr($request, $this->brandId);

    $response = $this->controller->deployNow($request);

    expect($response->getStatusCode())->toBe(400);
    expect(json_decode($response->getContent(), true)['message'])->toContain('No Oxygen deployment token');
});

it('domainStatus returns status=pending when oxygen_storefront_id is missing, status=live when present', function () {
    // Pending — no settings row.
    $request = \Illuminate\Http\Request::create('/internal/embedded/domain-status', 'GET');
    attachEmbeddedAttr($request, $this->brandId);

    $data = json_decode($this->controller->domainStatus($request)->getContent(), true);
    expect($data['status'])->toBe('pending');

    // Live — storefront id present.
    BrandStoreSettings::create([
        'professional_id' => $this->brandId,
        'oxygen_storefront_id' => 'gid://shopify/HydrogenStorefront/abc',
    ]);

    $data = json_decode($this->controller->domainStatus($request)->getContent(), true);
    expect($data['status'])->toBe('live');
});
