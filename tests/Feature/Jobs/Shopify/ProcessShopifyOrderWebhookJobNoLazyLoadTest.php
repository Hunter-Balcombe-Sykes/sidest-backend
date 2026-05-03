<?php

/** @phpstan-ignore-all */

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Customers\ContactCaptureService;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Phase 0's preventLazyLoading (AppServiceProvider::boot) makes this a regression
// test for the N+1: if the affiliate relation isn't preloaded before save, the
// observer's notifyBrandSale() will access affiliateProfessional->display_name
// lazily and throw LazyLoadingViolationException.

beforeEach(function () {
    config(['sidest.store.default_commission_rate' => 15]);

    DB::purge('pgsql');

    $conn = DB::connection('pgsql');
    foreach (['core', 'brand', 'commerce'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY, handle TEXT, handle_lc TEXT, display_name TEXT,
        professional_type TEXT, status TEXT DEFAULT "active",
        primary_email TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT
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
        occurred_at TEXT, payout_id TEXT, voided_at TEXT, void_reason TEXT,
        created_at TEXT, updated_at TEXT
    )');

    Queue::fake();

    // Silence the observer's publish() calls — the notifications schema doesn't
    // exist in the in-memory SQLite setup. We only care that the relation access
    // doesn't trigger a lazy-load violation.
    app()->instance(NotificationPublisher::class, Mockery::mock(NotificationPublisher::class)->shouldIgnoreMissing());
});

function seedForNoLazyLoadTest(): array
{
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $conn = DB::connection('pgsql');

    $conn->table('core.professionals')->insert([
        'id' => $brandId, 'handle' => 'brand1', 'handle_lc' => 'brand1',
        'display_name' => 'Brand One', 'professional_type' => 'brand', 'status' => 'active',
    ]);
    $conn->table('core.professionals')->insert([
        'id' => $affiliateId, 'handle' => 'sarah', 'handle_lc' => 'sarah',
        'display_name' => 'Sarah Stylist', 'professional_type' => 'professional', 'status' => 'active',
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

it('creates multiple ledger entries without triggering a lazy-load violation on the affiliate relation', function () {
    [$brandId] = seedForNoLazyLoadTest();

    $catalogMock = Mockery::mock(BrandCatalogService::class);
    $catalogMock->shouldReceive('fetchCommissionOverridesForProducts')
        ->once()
        ->andReturn([
            'gid://shopify/Product/101' => null,
            'gid://shopify/Product/102' => null,
        ]);
    app()->instance(BrandCatalogService::class, $catalogMock);

    $job = new ProcessShopifyOrderWebhookJob($brandId, [
        'id' => 'shop_order_n1_test',
        'currency' => 'AUD',
        'created_at' => now()->toIso8601String(),
        'note_attributes' => [['name' => 'affiliate', 'value' => 'sarah']],
        'line_items' => [
            [
                'id' => 'line_1', 'product_id' => '101',
                'price' => '80.00', 'quantity' => 1, 'total_discount' => '0',
                'properties' => [],
            ],
            [
                'id' => 'line_2', 'product_id' => '102',
                'price' => '60.00', 'quantity' => 2, 'total_discount' => '0',
                'properties' => [],
            ],
        ],
    ]);

    // Dispatching synchronously. preventLazyLoading is active in non-production
    // (including tests) — any unloaded relation access inside the observer throws.
    $job->handle(
        Mockery::mock(ContactCaptureService::class)->shouldIgnoreMissing(),
        app(BrandCatalogService::class),
    );

    // Sanity: both entries were created, confirming the loop ran twice.
    $count = CommissionLedgerEntry::query()->count();
    expect($count)->toBe(2);

    // Primary assertion: reaching here without LazyLoadingViolationException
    // confirms the affiliate relation was preloaded before observer execution.
    expect(true)->toBeTrue();
});
