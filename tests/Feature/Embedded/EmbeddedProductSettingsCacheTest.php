<?php

use App\Http\Controllers\Api\Internal\EmbeddedProductSettingsController;
use App\Http\Requests\Api\Internal\Embedded\UpdateProductSettingsRequest;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;

beforeEach(function () {
    Cache::flush();
    setupProfessionalsTable();
    setupProfessionalIntegrationsTable();
    setupBrandStoreSettingsTable();

    $this->professionalId = (string) Str::uuid();
    $this->productId = '9876543210';
    $this->productGid = "gid://shopify/Product/{$this->productId}";

    // update() loads Professional via ResolveEmbeddedProfessional (SEC-3) so
    // the Policy gate has an actor. Seed the brand row.
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $this->professionalId,
        'display_name' => 'Cache Test Brand',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Use the model's create() so encrypted casts run on access_token /
    // storefront_token (raw inserts would store plaintext in the encrypted col).
    $this->integration = ProfessionalIntegration::create([
        'id' => (string) Str::uuid(),
        'professional_id' => $this->professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'external_account_id' => 'test-shop.myshopify.com',
        'access_token' => 'shpat_test_admin',
        'storefront_token' => 'shpat_test_storefront',
        'provider_metadata' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'favourites_collection_handle' => 'partna-favourites',
            'default_collection_handle' => 'partna-default',
        ],
    ]);

    $this->controller = app(EmbeddedProductSettingsController::class);
});

afterEach(function () {
    Mockery::close();
});

// ── Cache hit on second mount ────────────────────────────────────────────────

it('show() serves the settings payload from cache without hitting Shopify', function () {
    $cached = [
        'active' => true,
        'commission_override' => 12.5,
        'affiliate_discount_pct' => null,
        'custom_photos_enabled' => null,
        'default_commission_rate' => 15.0,
        'global_custom_photos_enabled' => false,
        'in_favourites_collection' => true,
        'in_default_collection' => false,
        'variants' => [],
    ];

    Cache::put(
        CacheKeyGenerator::embeddedProductSettings($this->professionalId, $this->productId),
        $cached,
        300,
    );

    // Any HTTP call would mean the cache was bypassed — fail the test loudly.
    Http::preventStrayRequests();

    $request = Request::create('/internal/embedded/product-settings', 'GET', [
        'product_gid' => $this->productGid,
    ]);
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $response = $this->controller->show($request);
    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200);
    // toEqual rather than toBe — JSON round-trip on the cached float
    // 15.0 collapses to int 15 (".0" is dropped by json_encode for whole floats).
    expect($data)->toEqual($cached);
});

// ── Cache bust on successful update ──────────────────────────────────────────

it('update() busts the settings cache (primary + :stale) after a successful save', function () {
    $key = CacheKeyGenerator::embeddedProductSettings($this->professionalId, $this->productId);
    Cache::put($key, ['stub' => 'before-save'], 300);
    Cache::put($key.':stale', ['stub' => 'before-save-stale'], 3000);

    Http::fake([
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'product' => ['metafield' => null],
                'productUpdate' => ['product' => ['id' => $this->productGid], 'userErrors' => []],
            ],
        ], 200),
    ]);

    $request = UpdateProductSettingsRequest::create('/internal/embedded/product-settings', 'PATCH', [
        'product_gid' => $this->productGid,
        'field' => 'commission_override',
        'value' => 20,
    ]);
    $request->setValidator(validator($request->all(), $request->rules()));
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(200);
    expect(Cache::get($key))->toBeNull();
    expect(Cache::get($key.':stale'))->toBeNull();
});

it('update() also busts embedded:product-active when the active flag is toggled', function () {
    $activeKey = CacheKeyGenerator::embeddedProductActive($this->professionalId, $this->productId);
    Cache::put($activeKey, true, 600);

    Http::fake([
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'product' => ['metafield' => null],
                'productUpdate' => ['product' => ['id' => $this->productGid], 'userErrors' => []],
            ],
        ], 200),
    ]);

    $request = UpdateProductSettingsRequest::create('/internal/embedded/product-settings', 'PATCH', [
        'product_gid' => $this->productGid,
        'field' => 'active',
        'value' => false,
    ]);
    $request->setValidator(validator($request->all(), $request->rules()));
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(200);
    expect(Cache::get($activeKey))->toBeNull();
});

it('update() also busts brandProductCustomPhotos when custom_photos_enabled changes', function () {
    $cpKey = CacheKeyGenerator::brandProductCustomPhotos($this->professionalId, $this->productGid);
    Cache::put($cpKey, ['enabled' => true], 600);

    Http::fake([
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'product' => ['metafield' => null],
                'productUpdate' => ['product' => ['id' => $this->productGid], 'userErrors' => []],
            ],
        ], 200),
    ]);

    $request = UpdateProductSettingsRequest::create('/internal/embedded/product-settings', 'PATCH', [
        'product_gid' => $this->productGid,
        'field' => 'custom_photos_enabled',
        'value' => false,
    ]);
    $request->setValidator(validator($request->all(), $request->rules()));
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(200);
    expect(Cache::get($cpKey))->toBeNull();
});

it('update() does NOT bust the active cache when an unrelated field changes', function () {
    $activeKey = CacheKeyGenerator::embeddedProductActive($this->professionalId, $this->productId);
    Cache::put($activeKey, true, 600);

    Http::fake([
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'product' => ['metafield' => null],
                'productUpdate' => ['product' => ['id' => $this->productGid], 'userErrors' => []],
            ],
        ], 200),
    ]);

    $request = UpdateProductSettingsRequest::create('/internal/embedded/product-settings', 'PATCH', [
        'product_gid' => $this->productGid,
        'field' => 'commission_override',
        'value' => 30,
    ]);
    $request->setValidator(validator($request->all(), $request->rules()));
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $this->controller->update($request);

    // Untouched — only `active` writes should bust this key.
    expect(Cache::get($activeKey))->toBeTrue();
});

// ── Log-with-context on swallowed exceptions ────────────────────────────────

it('isInCollection logs a warning on a thrown exception and returns false', function () {
    Cache::flush();

    Http::fake(function ($request) {
        // Admin metafield query: succeed with no product so variants=[].
        if (str_contains($request->url(), '/admin/api/')) {
            return Http::response(['data' => ['product' => null]], 200);
        }

        // Storefront collection query: throw a connection-level exception.
        throw new \Illuminate\Http\Client\ConnectionException('storefront unreachable');
    });

    $logSpy = Log::spy();

    $request = Request::create('/internal/embedded/product-settings', 'GET', [
        'product_gid' => $this->productGid,
    ]);
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $response = $this->controller->show($request);

    expect($response->getStatusCode())->toBe(200);
    $payload = json_decode($response->getContent(), true);
    expect($payload['in_favourites_collection'])->toBeFalse();
    expect($payload['in_default_collection'])->toBeFalse();

    // Log::warning called at least once with the collection_handle context.
    $logSpy->shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx) {
        return str_contains($msg, 'collection membership')
            && ($ctx['collection_handle'] ?? null) !== null
            && ($ctx['shop_domain'] ?? null) === 'test-shop.myshopify.com'
            && ($ctx['error_class'] ?? null) === \Illuminate\Http\Client\ConnectionException::class;
    });
});

it('fetchVariants logs warning AND re-throws so update() returns 422 instead of false-success', function () {
    Http::fake(function ($request) {
        if (str_contains($request->body(), 'productVariants')) {
            // Variant fetch — surface a 5xx so the controller raises.
            return Http::response(['errors' => [['message' => 'Internal']]], 500);
        }

        return Http::response(['data' => ['product' => ['metafield' => null]]], 200);
    });

    $logSpy = Log::spy();

    $request = UpdateProductSettingsRequest::create('/internal/embedded/product-settings', 'PATCH', [
        'product_gid' => $this->productGid,
        'field' => 'disabled_variant_gids',
        'value' => ['gid://shopify/ProductVariant/111'],
    ]);
    $request->setValidator(validator($request->all(), $request->rules()));
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(422);

    $logSpy->shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx) {
        return str_contains($msg, 'fetching variants')
            && ($ctx['operation'] ?? null) === 'fetchVariants'
            && ($ctx['product_id'] ?? null) === $this->productId;
    });
});

// ── BrandCatalogService delegation in toggleCollection ──────────────────────

it('toggleCollection delegates collection-id resolution to BrandCatalogService', function () {
    $catalog = $this->mock(BrandCatalogService::class, function (MockInterface $m) {
        $m->shouldReceive('resolveCollectionGid')
            ->once()
            ->with(Mockery::on(fn ($int) => $int instanceof ProfessionalIntegration), 'partna-favourites')
            ->andReturn('gid://shopify/Collection/42');
    });

    // Re-resolve the controller so it picks up the mocked BrandCatalogService.
    $controller = app(EmbeddedProductSettingsController::class);

    Http::fake([
        // Only the add-products mutation should hit Shopify; the inline
        // collection(handle:){id} query is gone.
        'test-shop.myshopify.com/admin/api/*' => Http::response([
            'data' => [
                'collectionAddProducts' => ['userErrors' => []],
            ],
        ], 200),
    ]);

    $request = UpdateProductSettingsRequest::create('/internal/embedded/product-settings', 'PATCH', [
        'product_gid' => $this->productGid,
        'field' => 'add_to_favourites',
        'value' => true,
    ]);
    $request->setValidator(validator($request->all(), $request->rules()));
    $request->attributes->set('embedded_professional_id', $this->professionalId);

    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(200);

    // No raw collection-handle lookup query was sent — only the collection-add mutation.
    Http::assertSent(fn ($req) => str_contains($req->body(), 'collectionAddProducts'));
    Http::assertNotSent(fn ($req) => str_contains($req->body(), 'collectionId('));
});
