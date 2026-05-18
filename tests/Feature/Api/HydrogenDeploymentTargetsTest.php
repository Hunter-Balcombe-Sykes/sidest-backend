<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

beforeEach(function () {
    setupBrandStoreSettingsTable();
    setupProfessionalIntegrationsTable();
});

it('returns deployment targets for brands with an Oxygen token', function () {
    DB::connection('pgsql')->statement(
        'INSERT INTO brand.brand_store_settings (id, professional_id, oxygen_deployment_token, oxygen_storefront_id, created_at, updated_at)
         VALUES (?,?,?,?,?,?)',
        ['s1', 'pro-1', Crypt::encrypt('gh_mytoken', false), 'sf-1', now(), now()]
    );
    DB::connection('pgsql')->statement(
        'INSERT INTO core.professional_integrations (id, professional_id, provider, shopify_shop_domain, created_at, updated_at)
         VALUES (?,?,?,?,?,?)',
        ['i1', 'pro-1', 'shopify', 'shop1.myshopify.com', now(), now()]
    );

    getJson('/api/internal/hydrogen/deployment-targets')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.shop_domain', 'shop1.myshopify.com')
        ->assertJsonPath('0.oxygen_storefront_id', 'sf-1');
});

it('resolves shop domains for multiple brands in exactly two queries', function () {
    foreach (range(1, 3) as $i) {
        DB::connection('pgsql')->statement(
            'INSERT INTO brand.brand_store_settings (id, professional_id, oxygen_deployment_token, oxygen_storefront_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?)',
            ["s$i", "pro-$i", Crypt::encrypt("token$i", false), "sf-$i", now(), now()]
        );
        DB::connection('pgsql')->statement(
            'INSERT INTO core.professional_integrations (id, professional_id, provider, shopify_shop_domain, created_at, updated_at)
             VALUES (?,?,?,?,?,?)',
            ["i$i", "pro-$i", 'shopify', "shop{$i}.myshopify.com", now(), now()]
        );
    }

    DB::connection('pgsql')->enableQueryLog();
    getJson('/api/internal/hydrogen/deployment-targets')->assertOk();
    $count = count(DB::connection('pgsql')->getQueryLog());
    DB::connection('pgsql')->disableQueryLog();

    // 1 query for brand_store_settings + 1 for professional_integrations — not 1+N
    expect($count)->toBeLessThanOrEqual(2);
});

it('filters to a single brand when professional_id query param is provided', function () {
    foreach (range(1, 2) as $i) {
        DB::connection('pgsql')->statement(
            'INSERT INTO brand.brand_store_settings (id, professional_id, oxygen_deployment_token, oxygen_storefront_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?)',
            ["s$i", "pro-$i", Crypt::encrypt("token$i", false), "sf-$i", now(), now()]
        );
        DB::connection('pgsql')->statement(
            'INSERT INTO core.professional_integrations (id, professional_id, provider, shopify_shop_domain, created_at, updated_at)
             VALUES (?,?,?,?,?,?)',
            ["i$i", "pro-$i", 'shopify', "shop{$i}.myshopify.com", now(), now()]
        );
    }

    getJson('/api/internal/hydrogen/deployment-targets?professional_id=pro-1')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.shop_domain', 'shop1.myshopify.com');
});
