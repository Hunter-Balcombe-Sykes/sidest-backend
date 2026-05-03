<?php

/** @phpstan-ignore-all */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPublicPayloadSchema();
    setupProfessionalsTable();
    DB::connection('pgsql')->table('site.public_site_payload')->delete();
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
            'default_commission_rate' => (float) config('sidest.store.default_commission_rate', 15),
            'max_featured_products' => (int) config('sidest.store.max_featured_products', 10),
        ],
        'legal' => [
            'privacy_policy' => 'Privacy policy',
            'terms_and_conditions' => 'Terms and conditions',
            'active_privacy_source' => 'templated',
            'active_terms_source' => 'templated',
        ],
    ];

    DB::connection('pgsql')->table('site.public_site_payload')->insert([
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
    // Don't cast to float — PHP's json_encode strips trailing zeros from "15.0",
    // so by the time assertJsonPath decodes the response, the numeric becomes
    // an int. Expecting an int matches what the wire actually carries.
    $response->assertJsonPath('default_commission_rate', (int) config('sidest.store.default_commission_rate', 15));
    $response->assertJsonPath('max_featured_products', (int) config('sidest.store.max_featured_products', 10));
    $response->assertJsonPath('store.selected_products.0.shopify_product_id', 'gid://shopify/Product/111');

    $response->assertJsonCount(2, 'blocks');
    $response->assertJsonPath('blocks.0.id', 'section-1');
    $response->assertJsonPath('blocks.0.block_group', 'sections');
    $response->assertJsonPath('blocks.1.id', 'link-1');
    $response->assertJsonPath('blocks.1.block_group', 'links');
});

function setupPublicPayloadSchema(): void
{
    // Run on the pgsql connection (forced by BaseModel) so models see the
    // tables we create. ATTACH DATABASE is per-PDO-handle, so it must run
    // on the same handle the models will use.
    $conn = DB::connection('pgsql');
    $driver = $conn->getDriverName();

    if ($driver === 'sqlite') {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS site");
        } catch (\Throwable $e) {
            // Ignore if already attached.
        }

        // public_site_payload is a Postgres VIEW in production but treated as
        // a plain table in tests — easier to seed with raw inserts.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.public_site_payload (
            site_id TEXT PRIMARY KEY,
            professional_id TEXT NULL,
            subdomain TEXT NULL,
            payload TEXT NULL
        )');

        return;
    }

    $conn->statement('CREATE SCHEMA IF NOT EXISTS site');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.public_site_payload (
        site_id uuid PRIMARY KEY,
        professional_id uuid NULL,
        subdomain varchar(63) NULL,
        payload jsonb NULL
    )');
}
