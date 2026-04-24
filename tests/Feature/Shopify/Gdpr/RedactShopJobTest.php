<?php

use App\Jobs\Shopify\Gdpr\RedactShopJob;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
        $conn->statement('ATTACH DATABASE \':memory:\' AS commerce');
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

    $conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        full_name TEXT,
        source TEXT,
        notes TEXT,
        external_id TEXT,
        redacted_at TEXT,
        marketing_opt_in_cached INTEGER,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        brand_professional_id TEXT,
        shopify_product_gid TEXT,
        selected_variant_gids TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedShopRedactFixture(string $shopDomain = 'test-brand.myshopify.com'): array
{
    $professionalId = 'brand-'.uniqid();

    // shopify_shop_domain is a generated column in production — raw insert to bypass $fillable
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_live_token',
        'refresh_token' => 'shpat_refresh',
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $integration = ProfessionalIntegration::query()
        ->where('shopify_shop_domain', $shopDomain)
        ->first();

    AffiliateProductSelection::create([
        'affiliate_professional_id' => 'affiliate-other',
        'brand_professional_id' => $professionalId,
        'shopify_product_gid' => 'gid://shopify/Product/1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Capture instances — id is auto-generated (HasUuids); hardcoded ids not in $fillable
    $shopifyCustomer = Customer::create([
        'professional_id' => $professionalId,
        'email' => 'shopper@example.com',
        'phone' => '+1234567890',
        'full_name' => 'Real Shopper',
        'source' => 'shopify',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Non-Shopify customer — must survive the redact.
    $freshaCustomer = Customer::create([
        'professional_id' => $professionalId,
        'email' => 'walkin@example.com',
        'full_name' => 'Salon Walk-in',
        'source' => 'fresha',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gdpr = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_SHOP_REDACT,
        'shop_domain' => $shopDomain,
        'payload_hash' => str_repeat('a', 64),
        'payload' => ['shop_domain' => $shopDomain],
        'professional_id' => $professionalId,
        'received_at' => now(),
    ]);

    return compact('professionalId', 'integration', 'gdpr', 'shopifyCustomer', 'freshaCustomer');
}

it('leaves no integration row with tokens after the redact', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $survived = ProfessionalIntegration::query()
        ->where('shopify_shop_domain', 'test-brand.myshopify.com')
        ->exists();
    expect($survived)->toBeFalse();
});

it('deletes affiliate_product_selections scoped to the brand', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $remaining = AffiliateProductSelection::query()
        ->where('brand_professional_id', $ctx['professionalId'])
        ->count();

    expect($remaining)->toBe(0);
});

it('anonymises only shopify-sourced customers, preserving other sources', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $shopify = Customer::find($ctx['shopifyCustomer']->id);
    expect($shopify->email)->toStartWith('redacted-');
    expect($shopify->email)->toEndWith('@gdpr.sidest.io');
    expect($shopify->full_name)->toBe('Redacted Customer');
    expect($shopify->phone)->toBeNull();
    expect($shopify->redacted_at)->not->toBeNull();

    $fresha = Customer::find($ctx['freshaCustomer']->id);
    expect($fresha->email)->toBe('walkin@example.com');
    expect($fresha->redacted_at)->toBeNull();
});

it('deletes the integration row as the final step', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    expect(ProfessionalIntegration::find($ctx['integration']->id))->toBeNull();
});

it('marks the gdpr_requests row completed on success', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $fresh = GdprRequest::find($ctx['gdpr']->id);
    expect($fresh->status)->toBe(GdprRequest::STATUS_COMPLETED);
    expect($fresh->completed_at)->not->toBeNull();
});

it('is idempotent — re-running on a completed request is a no-op', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();
    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $fresh = GdprRequest::find($ctx['gdpr']->id);
    expect($fresh->status)->toBe(GdprRequest::STATUS_COMPLETED);
});

it('marks the request skipped when shop_domain no longer resolves', function () {
    $gdpr = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_SHOP_REDACT,
        'shop_domain' => 'ghost-shop.myshopify.com',
        'payload_hash' => str_repeat('c', 64),
        'payload' => ['shop_domain' => 'ghost-shop.myshopify.com'],
        'received_at' => now(),
    ]);

    (new RedactShopJob($gdpr->id))->handle();

    $fresh = GdprRequest::find($gdpr->id);
    expect($fresh->status)->toBe(GdprRequest::STATUS_SKIPPED);
    expect($fresh->error)->toContain('no integration');
});
