<?php

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyShopResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        provider_metadata TEXT,
        shopify_shop_domain TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');
});

function seedShopifyIntegration(string $shopDomain, string $professionalId): void
{
    // shopify_shop_domain is a generated column in production — use raw insert
    // to bypass $fillable and seed the SQLite column directly.
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test_token',
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('resolves a professional_id for a known shop_domain', function () {
    seedShopifyIntegration('test-brand.myshopify.com', 'brand-123');

    $resolver = new ShopifyShopResolver;
    $professionalId = $resolver->resolveProfessionalId('test-brand.myshopify.com');

    expect($professionalId)->toBe('brand-123');
});

it('normalises shop_domain to lowercase before lookup', function () {
    seedShopifyIntegration('test-brand.myshopify.com', 'brand-123');

    $resolver = new ShopifyShopResolver;
    $professionalId = $resolver->resolveProfessionalId('TEST-BRAND.myshopify.com');

    expect($professionalId)->toBe('brand-123');
});

it('returns null when no integration matches (already redacted or never installed)', function () {
    $resolver = new ShopifyShopResolver;

    expect($resolver->resolveProfessionalId('unknown.myshopify.com'))->toBeNull();
});

it('ignores non-Shopify integrations with same external_account_id', function () {
    // Seed a Fresha integration that happens to share an external id — resolver
    // must only match provider=shopify.
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => 'brand-fresha',
        'provider' => ProfessionalIntegration::PROVIDER_FRESHA,
        'external_account_id' => 'test-brand.myshopify.com',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver = new ShopifyShopResolver;

    expect($resolver->resolveProfessionalId('test-brand.myshopify.com'))->toBeNull();
});
