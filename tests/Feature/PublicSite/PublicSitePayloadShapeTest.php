<?php

/** @phpstan-ignore-all */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPublicPayloadSchema();
    DB::table('public_site_payload')->delete();
})->group('public-site-payload');

it('includes featured products and combined blocks in the public payload', function () {
    $siteId = (string) Str::uuid();
    $professionalId = (string) Str::uuid();
    $themeId = (string) Str::uuid();

    $payload = [
        'site' => [
            'id' => $siteId,
            'subdomain' => 'fadez',
            'settings' => [],
            'is_published' => true,
            'gallery' => [],
            'content_images' => [],
        ],
        'professional' => [
            'id' => $professionalId,
            'handle' => 'fadez',
            'display_name' => 'Fadez Studio',
        ],
        'theme' => [
            'id' => $themeId,
            'key' => 'modern',
            'name' => 'Modern',
            'config' => [],
        ],
        'links' => [
            [
                'id' => 'link-1',
                'block_type' => 'link',
                'title' => 'Book Now',
                'sort_order' => 2,
            ],
        ],
        'sections' => [
            [
                'id' => 'section-1',
                'block_type' => 'shop',
                'title' => 'Shop',
                'sort_order' => 1,
            ],
        ],
        'services' => [],
        'store' => [
            'selected_products' => [
                [
                    'shopify_product_id' => 'gid://shopify/Product/111',
                    'sort_order' => 0,
                ],
            ],
            'default_commission_rate' => (float) config('comet.store.default_commission_rate', 15),
            'max_featured_products' => (int) config('comet.store.max_featured_products', 10),
        ],
        'legal' => [
            'privacy_policy' => 'Privacy policy',
            'terms_and_conditions' => 'Terms and conditions',
            'active_privacy_source' => 'templated',
            'active_terms_source' => 'templated',
        ],
    ];

    DB::table('public_site_payload')->insert([
        'site_id' => $siteId,
        'professional_id' => $professionalId,
        'subdomain' => 'fadez',
        'payload' => json_encode($payload),
    ]);

    $response = $this
        ->withHeader('X-Site-Subdomain', 'fadez')
        ->getJson('/api/public/site-by-slug');

    $response->assertOk();
    $response->assertJsonPath('selected_products.0.shopify_product_id', 'gid://shopify/Product/111');
    $response->assertJsonPath('default_commission_rate', (float) config('comet.store.default_commission_rate', 15));
    $response->assertJsonPath('max_featured_products', (int) config('comet.store.max_featured_products', 10));
    $response->assertJsonPath('store.selected_products.0.shopify_product_id', 'gid://shopify/Product/111');

    $response->assertJsonCount(2, 'blocks');
    $response->assertJsonPath('blocks.0.id', 'section-1');
    $response->assertJsonPath('blocks.0.block_group', 'sections');
    $response->assertJsonPath('blocks.1.id', 'link-1');
    $response->assertJsonPath('blocks.1.block_group', 'links');
});

function setupPublicPayloadSchema(): void
{
    $driver = DB::getDriverName();

    if ($driver === 'sqlite') {
        try {
            DB::statement("ATTACH DATABASE ':memory:' AS core");
        } catch (\Throwable $e) {
            // Ignore if already attached.
        }

        DB::statement('CREATE TABLE IF NOT EXISTS core.public_site_payload (
            site_id TEXT PRIMARY KEY,
            professional_id TEXT NULL,
            subdomain TEXT NULL,
            payload TEXT NULL
        )');

        return;
    }

    DB::statement('CREATE SCHEMA IF NOT EXISTS core');

    DB::statement('CREATE TABLE IF NOT EXISTS core.public_site_payload (
        site_id uuid PRIMARY KEY,
        professional_id uuid NULL,
        subdomain varchar(63) NULL,
        payload jsonb NULL
    )');
}
