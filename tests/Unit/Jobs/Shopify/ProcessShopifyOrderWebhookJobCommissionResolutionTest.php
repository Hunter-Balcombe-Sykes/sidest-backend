<?php

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Customers\ContactCaptureService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config(['sidest.store.default_commission_rate' => 15]);

    // TestCase already redirects 'pgsql' → in-memory SQLite and sets default=pgsql.
    // Purge to get a fresh handle, then attach the schemas we need.
    DB::purge('pgsql');

    $conn = DB::connection('pgsql');
    foreach (['core', 'brand', 'commerce'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {}
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY, handle TEXT, handle_lc TEXT, professional_type TEXT,
        status TEXT DEFAULT "active", primary_email TEXT, deleted_at TEXT,
        created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY, professional_id TEXT, provider TEXT,
        access_token TEXT, provider_metadata TEXT,
        created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY, affiliate_professional_id TEXT,
        brand_professional_id TEXT, slot INTEGER DEFAULT 0,
        created_at TEXT, updated_at TEXT
    )');
    // BrandStoreSettings uses brand.brand_store_settings (not retail)
    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY, professional_id TEXT,
        default_commission_rate REAL, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
        id TEXT PRIMARY KEY,
        shopify_order_id TEXT, brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        entry_type TEXT, status TEXT,
        amount_cents INTEGER, currency_code TEXT,
        commission_rate REAL, rate_source TEXT,
        idempotency_key TEXT UNIQUE,
        calculation_metadata TEXT,
        occurred_at TEXT, created_at TEXT, updated_at TEXT
    )');

    // Prevent analytics jobs from trying to dispatch to a real queue
    Queue::fake();
});

function seedAffiliateAndBrandForJob(): array
{
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $conn = DB::connection('pgsql');

    // Use raw inserts so UUIDs are deterministic — Professional uses HasUuids
    // which ignores id in mass-assignment since id is not in $fillable.
    $conn->table('core.professionals')->insert([
        'id' => $brandId, 'handle' => 'brand1', 'handle_lc' => 'brand1',
        'professional_type' => 'brand', 'status' => 'active',
    ]);
    $conn->table('core.professionals')->insert([
        'id' => $affiliateId, 'handle' => 'sarah', 'handle_lc' => 'sarah',
        'professional_type' => 'professional', 'status' => 'active',
    ]);
    $conn->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id' => $brandId,
        'slot' => 0,
    ]);
    $conn->table('brand.brand_store_settings')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'default_commission_rate' => 10.0,
    ]);
    $conn->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test',
        'provider_metadata' => json_encode(['shop_domain' => 'test-shop.myshopify.com']),
    ]);

    return [$brandId, $affiliateId];
}

it('ignores a buyer-inflated line-item commission rate and uses the brand default', function () {
    [$brandId] = seedAffiliateAndBrandForJob();

    // Mock BrandCatalogService — no metafield override, so brand default applies
    $catalogMock = Mockery::mock(BrandCatalogService::class);
    $catalogMock->shouldReceive('fetchCommissionOverridesForProducts')
        ->once()
        ->andReturn(['gid://shopify/Product/1' => null]);
    app()->instance(BrandCatalogService::class, $catalogMock);

    $job = new ProcessShopifyOrderWebhookJob($brandId, [
        'id' => 'shop_order_1',
        'currency' => 'AUD',
        'created_at' => now()->toIso8601String(),
        'note_attributes' => [['name' => 'affiliate', 'value' => 'sarah']],
        'line_items' => [[
            'id' => 'line_1',
            'product_id' => '1',
            'price' => '100.00',
            'quantity' => 1,
            'total_discount' => '0',
            // Buyer-inflated rate — must be ignored
            'properties' => [['name' => 'sidest_commission_rate', 'value' => '99']],
        ]],
    ]);

    $job->handle(
        Mockery::mock(ContactCaptureService::class)->shouldIgnoreMissing(),
        app(BrandCatalogService::class)
    );

    $entry = CommissionLedgerEntry::query()->first();
    expect($entry)->not->toBeNull();
    // $100 * 10% (brand default) = $10.00 = 1000 cents. NOT $99.
    expect($entry->amount_cents)->toBe(1000);
    expect((float) $entry->commission_rate)->toBe(10.0);
    expect($entry->rate_source)->toBe('brand_default');

    $meta = $entry->calculation_metadata;
    // Audit trail: the buyer's submitted rate is recorded but not applied
    expect($meta['submitted_rate'] ?? null)->toBe('99');
});

it('uses the product metafield commission_override when present', function () {
    [$brandId] = seedAffiliateAndBrandForJob();

    $catalogMock = Mockery::mock(BrandCatalogService::class);
    $catalogMock->shouldReceive('fetchCommissionOverridesForProducts')
        ->once()
        ->andReturn(['gid://shopify/Product/1' => 25.0]);
    app()->instance(BrandCatalogService::class, $catalogMock);

    $job = new ProcessShopifyOrderWebhookJob($brandId, [
        'id' => 'shop_order_2',
        'currency' => 'AUD',
        'created_at' => now()->toIso8601String(),
        'note_attributes' => [['name' => 'affiliate', 'value' => 'sarah']],
        'line_items' => [[
            'id' => 'line_1',
            'product_id' => '1',
            'price' => '100.00',
            'quantity' => 1,
            'total_discount' => '0',
            'properties' => [],
        ]],
    ]);

    $job->handle(
        Mockery::mock(ContactCaptureService::class)->shouldIgnoreMissing(),
        app(BrandCatalogService::class)
    );

    $entry = CommissionLedgerEntry::query()->first();
    // $100 * 25% = $25.00 = 2500 cents
    expect($entry->amount_cents)->toBe(2500);
    expect($entry->rate_source)->toBe('metafield_override');
});

it('falls back to platform default when brand has no store settings', function () {
    [$brandId] = seedAffiliateAndBrandForJob();

    // Remove brand store settings so fallback triggers
    BrandStoreSettings::query()->where('professional_id', $brandId)->delete();

    $catalogMock = Mockery::mock(BrandCatalogService::class);
    $catalogMock->shouldReceive('fetchCommissionOverridesForProducts')
        ->once()
        ->andReturn(['gid://shopify/Product/1' => null]);
    app()->instance(BrandCatalogService::class, $catalogMock);

    $job = new ProcessShopifyOrderWebhookJob($brandId, [
        'id' => 'shop_order_3',
        'currency' => 'AUD',
        'created_at' => now()->toIso8601String(),
        'note_attributes' => [['name' => 'affiliate', 'value' => 'sarah']],
        'line_items' => [[
            'id' => 'line_1',
            'product_id' => '1',
            'price' => '100.00',
            'quantity' => 1,
            'total_discount' => '0',
            'properties' => [],
        ]],
    ]);

    $job->handle(
        Mockery::mock(ContactCaptureService::class)->shouldIgnoreMissing(),
        app(BrandCatalogService::class)
    );

    $entry = CommissionLedgerEntry::query()->first();
    // $100 * 15% (platform default) = $15.00 = 1500 cents
    expect($entry->amount_cents)->toBe(1500);
    expect($entry->rate_source)->toBe('platform_default');
});

it('batches metafield lookup across multiple line items of distinct products', function () {
    [$brandId] = seedAffiliateAndBrandForJob();

    $catalogMock = Mockery::mock(BrandCatalogService::class);
    // SINGLE call for both products
    $catalogMock->shouldReceive('fetchCommissionOverridesForProducts')
        ->once()
        ->with(Mockery::any(), Mockery::on(fn ($gids) => count($gids) === 2))
        ->andReturn([
            'gid://shopify/Product/1' => 20.0,
            'gid://shopify/Product/2' => null,
        ]);
    app()->instance(BrandCatalogService::class, $catalogMock);

    $job = new ProcessShopifyOrderWebhookJob($brandId, [
        'id' => 'shop_order_4',
        'currency' => 'AUD',
        'created_at' => now()->toIso8601String(),
        'note_attributes' => [['name' => 'affiliate', 'value' => 'sarah']],
        'line_items' => [
            [
                'id' => 'line_1', 'product_id' => '1',
                'price' => '50.00', 'quantity' => 1, 'total_discount' => '0',
                'properties' => [],
            ],
            [
                'id' => 'line_2', 'product_id' => '2',
                'price' => '50.00', 'quantity' => 1, 'total_discount' => '0',
                'properties' => [],
            ],
        ],
    ]);

    $job->handle(
        Mockery::mock(ContactCaptureService::class)->shouldIgnoreMissing(),
        app(BrandCatalogService::class)
    );

    $entries = CommissionLedgerEntry::query()->orderBy('idempotency_key')->get();
    expect($entries)->toHaveCount(2);
    // Product 1: $50 * 20% = 1000 cents (metafield)
    // Product 2: $50 * 10% = 500 cents (brand default)
    expect($entries[0]->amount_cents)->toBe(1000);
    expect($entries[0]->rate_source)->toBe('metafield_override');
    expect($entries[1]->amount_cents)->toBe(500);
    expect($entries[1]->rate_source)->toBe('brand_default');
});
