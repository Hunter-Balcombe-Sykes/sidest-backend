<?php

use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// End-to-end coverage for SyncShopifyBrandDesignJob — the job that runs after
// every Shopify shop/update webhook, manual resync, and onboarding install
// step. The previous breakage (a missing class file) made it past CI because
// nothing exercised the dispatch path; these tests close that gap.
//
// We dispatchSync() to bypass the queue layer (so ShouldBeUnique / unique
// locks aren't relevant here) and run the handler in-process against fake
// Shopify endpoints + a fake media disk.

beforeEach(function () {
    $conn = DB::connection('pgsql');
    foreach (['core', 'site'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    setupSitesTable();

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

    // The job resolves its disk via mediaDiskName(), which defaults to 'media'
    // when sidest.media_disk is unset and the default disk isn't s3. Fake
    // 'media' so the logo-mirror writes land somewhere we can assert on.
    Storage::fake('media');
});

function seedBrandDesignJobFixtures(array $existingDesign = []): ProfessionalIntegration
{
    $proId = 'pro-bdjob-1';
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => 'site-bdjob-1',
        'professional_id' => $proId,
        'subdomain' => 'bdjob',
        'settings' => json_encode(['design' => $existingDesign]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $integration = new ProfessionalIntegration([
        'professional_id' => $proId,
        'provider' => 'shopify',
        'external_account_id' => 'bdjob.myshopify.com',
        'access_token' => 'shpat_test_token',
        'provider_metadata' => ['shop_domain' => 'bdjob.myshopify.com'],
    ]);
    $integration->id = 'int-bdjob-1';
    $integration->save();

    return $integration;
}

// Single Http::fake() call covering every endpoint the job hits:
//   1. graphql.json — three different query bodies (shop brand, themes,
//      metafieldsSet) routed by inspecting the request body.
//   2. assets.json (REST) — settings_data.json blob.
//   3. cdn.shopify.com/* — logo bytes.
function fakeBrandDesignJobShopify(?array $brandColors = null): void
{
    $colors = $brandColors ?? [
        'primary' => [['background' => '#ababab', 'foreground' => '#121212']],
        'secondary' => [['background' => '#cd00ef', 'foreground' => '#ffffff']],
    ];

    Http::fake([
        'bdjob.myshopify.com/admin/api/*/graphql.json' => function ($request) use ($colors) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'metafieldsSet')) {
                return Http::response([
                    'data' => [
                        'metafieldsSet' => [
                            'metafields' => [['id' => 'gid://shopify/Metafield/1', 'key' => 'brand_design']],
                            'userErrors' => [],
                        ],
                    ],
                ]);
            }
            if (str_contains($query, 'shop {')) {
                return Http::response([
                    'data' => [
                        'shop' => [
                            'id' => 'gid://shopify/Shop/777',
                            'myshopifyDomain' => 'bdjob.myshopify.com',
                            'brand' => [
                                'slogan' => 'Job test slogan',
                                'logo' => ['image' => ['url' => 'https://cdn.shopify.com/s/files/full.png']],
                                'squareLogo' => ['image' => ['url' => 'https://cdn.shopify.com/s/files/square.png']],
                                'colors' => $colors,
                            ],
                        ],
                    ],
                ]);
            }
            if (str_contains($query, 'themes(')) {
                return Http::response([
                    'data' => [
                        'themes' => [
                            'nodes' => [
                                ['id' => 'gid://shopify/OnlineStoreTheme/77', 'name' => 'Dawn', 'role' => 'MAIN'],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response(['errors' => [['message' => 'unexpected query']]], 500);
        },
        'bdjob.myshopify.com/admin/api/*/themes/*/assets.json*' => Http::response([
            'asset' => [
                'key' => 'config/settings_data.json',
                'value' => json_encode(['current' => ['buttons_radius' => 8]]),
            ],
        ]),
        'cdn.shopify.com/*' => Http::response(
            "\x89PNG\r\n\x1a\nfakebytes",
            200,
            ['Content-Type' => 'image/png']
        ),
    ]);
}

it('writes the full brand design shape into site.settings.design end-to-end', function () {
    $integration = seedBrandDesignJobFixtures();
    fakeBrandDesignJobShopify();

    SyncShopifyBrandDesignJob::dispatchSync($integration->id);

    $site = Site::query()->where('professional_id', 'pro-bdjob-1')->first();
    $design = $site->settings['design'] ?? [];

    expect($design['colors']['background'])->toBe('#ababab');
    expect($design['colors']['text'])->toBe('#121212');
    expect($design['colors']['accent'])->toBe('#cd00ef');
    expect($design['colors']['border'])->toBeNull();
    expect($design['corner_radius'])->toBe('default');
    expect($design['slogan'])->toBe('Job test slogan');
    expect($design['logo']['full_url'])->not->toBeNull();
    expect($design['logo']['square_url'])->not->toBeNull();
    expect($design['logo']['full_url'])->toContain('logo_full_');
    expect($design['logo']['square_url'])->toContain('logo_square_');
});

it('preserves an existing user accent colour when Shopify has no accent', function () {
    // Pre-seed a user-edited accent. Shopify will return only the primary
    // colour pair (background + text) and an empty secondary array — the
    // job's leave-if-absent merge must keep the user's accent intact.
    $integration = seedBrandDesignJobFixtures([
        'colors' => [
            'background' => null,
            'text' => null,
            'accent' => '#user-set-purple',
            'border' => null,
        ],
    ]);

    fakeBrandDesignJobShopify([
        'primary' => [['background' => '#ababab', 'foreground' => '#121212']],
        'secondary' => [],
    ]);

    SyncShopifyBrandDesignJob::dispatchSync($integration->id);

    $site = Site::query()->where('professional_id', 'pro-bdjob-1')->first();
    $design = $site->settings['design'];

    expect($design['colors']['background'])->toBe('#ababab');         // overwritten by Shopify
    expect($design['colors']['accent'])->toBe('#user-set-purple');    // preserved (Shopify had nothing)
});

it('is a no-op when the integration row does not exist', function () {
    Http::preventStrayRequests();

    // No rows in core.professional_integrations and no HTTP fakes — the job
    // should query, find nothing, and return cleanly.
    SyncShopifyBrandDesignJob::dispatchSync('does-not-exist');

    expect(true)->toBeTrue();
});

it('rethrows when the importer fails so Laravel retries', function () {
    $integration = seedBrandDesignJobFixtures();

    // Fail every Shopify call. fetchBrand() throws on a non-ok response,
    // import() bubbles it, the job's try/catch logs and rethrows — which is
    // what triggers Laravel's retry/backoff machinery in production.
    Http::fake([
        'bdjob.myshopify.com/*' => Http::response(['errors' => [['message' => 'boom']]], 500),
    ]);

    expect(fn () => SyncShopifyBrandDesignJob::dispatchSync($integration->id))
        ->toThrow(\RuntimeException::class);
});
