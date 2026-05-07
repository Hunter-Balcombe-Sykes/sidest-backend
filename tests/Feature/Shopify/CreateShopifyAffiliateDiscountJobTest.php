<?php

use App\Jobs\Shopify\CreateShopifyAffiliateDiscountJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// End-to-end coverage for CreateShopifyAffiliateDiscountJob — dispatched at
// the end of the Shopify OAuth chain and by sidest:install-affiliate-discount
// for existing brands. Stubs the Shopify Admin API so we can assert the exact
// GraphQL calls the job makes and the provider_metadata state transitions it
// writes.

beforeEach(function () {
    // Suppress the BackfillBrandHasEnabledVariantsJob dispatched at the end of
    // handle() — its schema concerns are unrelated to what these tests verify
    // (HTTP calls + provider_metadata state). Cleaner than seeding the
    // professionals table just to keep an unrelated downstream job happy.
    Queue::fake();

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
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');
});

function makeShopifyIntegration(array $meta = []): ProfessionalIntegration
{
    $defaults = [
        'shop_domain' => 'test-brand.myshopify.com',
    ];

    // Pass provider_metadata as an array, not a pre-encoded JSON string.
    // The 'array' cast on the model would double-encode a JSON string,
    // leaving the job with a string it can't extract shop_domain from.
    return ProfessionalIntegration::create([
        'id' => 'int-'.uniqid(),
        'professional_id' => 'brand-'.uniqid(),
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test_token',
        'provider_metadata' => array_merge($defaults, $meta),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('installs automatic app discount when function is present and none exists', function () {
    $integration = makeShopifyIntegration();

    Http::fake([
        'test-brand.myshopify.com/admin/api/*/graphql.json' => Http::sequence()
            // 1. shopifyFunctions query — function exists
            ->push([
                'data' => [
                    'shopifyFunctions' => [
                        'edges' => [
                            ['node' => [
                                'id' => 'gid://shopify/ShopifyFunction/abc123',
                                'apiType' => 'discount',
                                'title' => 'sidest-affiliate-discount',
                                'app' => ['title' => 'Partna'],
                            ]],
                        ],
                    ],
                ],
            ], 200)
            // 2. automaticDiscountNodes query — none installed yet
            ->push([
                'data' => ['automaticDiscountNodes' => ['edges' => []]],
            ], 200)
            // 3. discountAutomaticAppCreate mutation — success
            ->push([
                'data' => [
                    'discountAutomaticAppCreate' => [
                        'automaticAppDiscount' => [
                            'discountId' => 'gid://shopify/DiscountAutomaticNode/999',
                            'title' => 'Partna Price',
                            'status' => 'ACTIVE',
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
    ]);

    app()->call([new CreateShopifyAffiliateDiscountJob($integration->id), 'handle']);

    $integration->refresh();
    $meta = is_array($integration->provider_metadata) ? $integration->provider_metadata : json_decode($integration->provider_metadata, true);
    expect($meta['sidest_discount_state'] ?? null)->toBe('registered');
});

it('skips create when automatic discount backed by function already exists', function () {
    $integration = makeShopifyIntegration();

    Http::fake([
        'test-brand.myshopify.com/admin/api/*/graphql.json' => Http::sequence()
            ->push([
                'data' => [
                    'shopifyFunctions' => [
                        'edges' => [
                            ['node' => [
                                'id' => 'gid://shopify/ShopifyFunction/abc123',
                                'apiType' => 'discount',
                                'title' => 'sidest-affiliate-discount',
                                'app' => ['title' => 'Partna'],
                            ]],
                        ],
                    ],
                ],
            ], 200)
            // Already installed — same function_id
            ->push([
                'data' => [
                    'automaticDiscountNodes' => [
                        'edges' => [
                            ['node' => [
                                'id' => 'gid://shopify/DiscountAutomaticNode/existing',
                                'automaticDiscount' => [
                                    'title' => 'Partna Price',
                                    'status' => 'ACTIVE',
                                    'appDiscountType' => [
                                        'functionId' => 'gid://shopify/ShopifyFunction/abc123',
                                        'title' => 'sidest-affiliate-discount',
                                    ],
                                ],
                            ]],
                        ],
                    ],
                ],
            ], 200),
    ]);

    app()->call([new CreateShopifyAffiliateDiscountJob($integration->id), 'handle']);

    $integration->refresh();
    $meta = is_array($integration->provider_metadata) ? $integration->provider_metadata : json_decode($integration->provider_metadata, true);
    expect($meta['sidest_discount_state'] ?? null)->toBe('registered');

    // No third call should have been made — assert the mutation wasn't fired.
    Http::assertSentCount(2);
});

it('marks state as pending when function is not present on the store yet', function () {
    $integration = makeShopifyIntegration();

    Http::fake([
        'test-brand.myshopify.com/admin/api/*/graphql.json' => Http::response([
            'data' => ['shopifyFunctions' => ['edges' => []]],
        ], 200),
    ]);

    app()->call([new CreateShopifyAffiliateDiscountJob($integration->id), 'handle']);

    $integration->refresh();
    $meta = is_array($integration->provider_metadata) ? $integration->provider_metadata : json_decode($integration->provider_metadata, true);
    // Pending, not failed — a later `shopify app deploy` that rolls the
    // function out to all stores lets a retry complete cleanly.
    expect($meta['sidest_discount_state'] ?? null)->toBe('pending');
});

it('marks state as failed when shop_domain is malformed', function () {
    $integration = makeShopifyIntegration(['shop_domain' => 'not-a-shopify-domain.com']);

    app()->call([new CreateShopifyAffiliateDiscountJob($integration->id), 'handle']);

    $integration->refresh();
    $meta = is_array($integration->provider_metadata) ? $integration->provider_metadata : json_decode($integration->provider_metadata, true);
    expect($meta['sidest_discount_state'] ?? null)->toBe('failed');
});
