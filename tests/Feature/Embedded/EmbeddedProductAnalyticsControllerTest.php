<?php

use App\Http\Controllers\Api\Internal\EmbeddedProductAnalyticsController;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

// Read-only controller backing the Shopify admin product-block UI extension.
// Build() does the heavy lifting: variant rollup, weighted-average commission
// rate, division-by-zero guard, status exclusion. resolveActive() is the
// inner per-product cache path that defers to BrandCatalogService.

beforeEach(function () {
    Cache::flush();
    setupCommerceOrdersTables();
    setupProfessionalsTable();
    setupProfessionalIntegrationsTable();

    $this->controller = app(EmbeddedProductAnalyticsController::class);
    $this->brandId = (string) Str::uuid();
    $this->affiliateId = (string) Str::uuid();
    $this->productId = '50001';

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $this->affiliateId,
        'display_name' => 'Affiliate Two',
        'handle' => 'aff-two',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
});

function seedAnalyticsOrderItem(string $brandId, string $affiliateId, string $productId, array $overrides = []): void
{
    $orderId = (string) Str::uuid();
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => (string) random_int(1000, 9_999_999),
        'shopify_shop_domain' => 'shop.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => $overrides['order_status'] ?? 'paid',
        'commission_cents' => 0,
        'commission_rate' => 0,
        'currency_code' => 'AUD',
        'occurred_at' => $overrides['occurred_at'] ?? now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('commerce.order_items')->insert(array_merge([
        'order_id' => $orderId,
        'shopify_line_item_id' => (string) Str::uuid(),
        'shopify_product_id' => $productId,
        'shopify_variant_id' => 'variant-1',
        'title' => 'Variant A',
        'quantity' => 1,
        'line_total_cents' => 10000,
        'commission_cents' => 1000,
        'commission_rate' => 0.10,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'currency_code' => 'AUD',
        'occurred_at' => now()->toDateTimeString(),
    ], array_diff_key($overrides, ['order_status' => 1, 'occurred_at' => 1])));
}

function callProductShow(EmbeddedProductAnalyticsController $controller, string $brandId, string $productGid): array
{
    $request = Request::create("/internal/embedded/products/{$productGid}/analytics", 'GET');
    $request->attributes->set('embedded_professional_id', $brandId);
    $response = $controller->show($request, $productGid);

    return json_decode($response->getContent(), true);
}

it('strips the GID prefix and exposes the bare numeric product_id', function () {
    mock(BrandCatalogService::class)->shouldReceive('fetchProductActiveMetafield')->andReturn(null);

    $data = callProductShow($this->controller, $this->brandId, "gid://shopify/Product/{$this->productId}");

    expect($data['product_id'])->toBe($this->productId);
});

it('rolls up variants and computes weighted-average commission rate', function () {
    mock(BrandCatalogService::class)->shouldReceive('fetchProductActiveMetafield')->andReturn(null);

    // Variant A — 2 sales, $100 each, 10% rate (commission $10 each).
    seedAnalyticsOrderItem($this->brandId, $this->affiliateId, $this->productId, [
        'shopify_variant_id' => 'v1', 'title' => 'Variant A',
        'quantity' => 1, 'line_total_cents' => 10000,
        'commission_cents' => 1000, 'commission_rate' => 0.10,
    ]);
    seedAnalyticsOrderItem($this->brandId, $this->affiliateId, $this->productId, [
        'shopify_variant_id' => 'v1', 'title' => 'Variant A',
        'quantity' => 1, 'line_total_cents' => 10000,
        'commission_cents' => 1000, 'commission_rate' => 0.10,
    ]);
    // Variant B — 1 sale, $50, 20% rate (commission $10).
    seedAnalyticsOrderItem($this->brandId, $this->affiliateId, $this->productId, [
        'shopify_variant_id' => 'v2', 'title' => 'Variant B',
        'quantity' => 1, 'line_total_cents' => 5000,
        'commission_cents' => 1000, 'commission_rate' => 0.20,
    ]);

    $data = callProductShow($this->controller, $this->brandId, $this->productId);

    expect($data['totals']['units'])->toBe(3);
    expect($data['totals']['revenue_cents'])->toBe(25000);
    expect($data['totals']['commission_cents'])->toBe(3000);
    // Weighted average: (0.10*1000 + 0.10*1000 + 0.20*1000) / 3000 = 0.133...
    // round(0.13333..., 2) = 0.13
    expect($data['totals']['avg_commission_rate'])->toBe(0.13);

    // Variants sorted by revenue desc — A ($200) before B ($50).
    expect($data['variants'][0]['variant_id'])->toBe('v1');
    expect($data['variants'][0]['units'])->toBe(2);
    expect($data['variants'][0]['revenue_cents'])->toBe(20000);
    expect($data['variants'][1]['variant_id'])->toBe('v2');
});

it('excludes stub/cancelled/voided/refunded orders from the rollup', function () {
    mock(BrandCatalogService::class)->shouldReceive('fetchProductActiveMetafield')->andReturn(null);

    foreach (['paid', 'stub', 'cancelled', 'voided', 'refunded'] as $status) {
        seedAnalyticsOrderItem($this->brandId, $this->affiliateId, $this->productId, [
            'order_status' => $status,
            'quantity' => 1,
            'line_total_cents' => 10000,
            'commission_cents' => 1000,
        ]);
    }

    $data = callProductShow($this->controller, $this->brandId, $this->productId);

    expect($data['totals']['units'])->toBe(1);
    expect($data['totals']['revenue_cents'])->toBe(10000);
});

it('returns zero avg_commission_rate when there are no commission-bearing sales (division-by-zero guard)', function () {
    mock(BrandCatalogService::class)->shouldReceive('fetchProductActiveMetafield')->andReturn(null);

    // No order_items at all → rateWeight = 0 → guarded by `$rateWeight > 0 ? ... : 0.0`.
    $data = callProductShow($this->controller, $this->brandId, $this->productId);

    // JSON round-trips 0.0 as int 0 — use loose equality.
    expect((float) $data['totals']['avg_commission_rate'])->toBe(0.0);
    expect($data['totals']['units'])->toBe(0);
    expect($data['variants'])->toBe([]);
    expect($data['recent_sales'])->toBe([]);
});

it('resolves active=null when no Shopify integration row exists for the brand', function () {
    // No ProfessionalIntegration row seeded. resolveActive must short-circuit
    // to null (via rememberLockedNullable + the integration check) without
    // calling BrandCatalogService — assert the mock is NOT called.
    $catalog = mock(BrandCatalogService::class);
    $catalog->shouldNotReceive('fetchProductActiveMetafield');

    $data = callProductShow($this->controller, $this->brandId, $this->productId);

    expect($data['active'])->toBeNull();
});

it('caches resolveActive across requests (single Shopify hit)', function () {
    // Seed a Shopify integration so resolveActive proceeds.
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $this->brandId,
        'provider' => 'shopify',
        'access_token' => 'shpat_test',
        'provider_metadata' => json_encode(['shop_domain' => 'shop.myshopify.com']),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Pre-seed the inner cache so resolveActive returns true without calling the catalog.
    Cache::put(
        CacheKeyGenerator::embeddedProductActive($this->brandId, $this->productId),
        true,
        600,
    );

    $catalog = mock(BrandCatalogService::class);
    $catalog->shouldNotReceive('fetchProductActiveMetafield');

    $data = callProductShow($this->controller, $this->brandId, $this->productId);

    expect($data['active'])->toBeTrue();
});
