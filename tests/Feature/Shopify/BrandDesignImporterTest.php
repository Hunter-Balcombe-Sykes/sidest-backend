<?php

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\BrandDesignImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Locks down the public contract of BrandDesignImporter:
//   1. The bucket thresholds (square|default|pill, hairline|default|bold,
//      tight|default|spacious) match the design brief.
//   2. Per-theme settings_data.json key resolution falls through to `generic`
//      for unknown themes.
//   3. The shop-domain regex guard rejects non-Shopify hosts.
//   4. Asset endpoint failures degrade gracefully — brand fields still come
//      through, enums become null.
//
// All Shopify HTTP is faked. Two GraphQL queries (shop brand, themes) hit the
// same URL — the closure routes by inspecting the query body.

beforeEach(function () {
    $conn = DB::connection('pgsql');
    foreach (['core'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
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

function makeBrandDesignImporterIntegration(string $shopDomain = 'importer.myshopify.com'): ProfessionalIntegration
{
    $integration = new ProfessionalIntegration([
        'professional_id' => 'pro-importer-1',
        'provider' => 'shopify',
        'external_account_id' => $shopDomain,
        'access_token' => 'shpat_test_token',
        'provider_metadata' => ['shop_domain' => $shopDomain],
    ]);
    $integration->id = 'int-importer-'.substr(md5($shopDomain), 0, 8);
    $integration->save();

    return $integration;
}

function brandDesignFakeShopBrandResponse(): array
{
    return [
        'data' => [
            'shop' => [
                'id' => 'gid://shopify/Shop/12345',
                'myshopifyDomain' => 'importer.myshopify.com',
                'brand' => [
                    'slogan' => 'Fresh and clean',
                    'logo' => ['image' => ['url' => 'https://cdn.shopify.com/s/files/full-logo.png']],
                    'squareLogo' => ['image' => ['url' => 'https://cdn.shopify.com/s/files/square-logo.png']],
                    'colors' => [
                        'primary' => [['background' => '#ffffff', 'foreground' => '#000000']],
                        'secondary' => [['background' => '#ff0066', 'foreground' => '#ffffff']],
                    ],
                ],
            ],
        ],
    ];
}

function brandDesignFakeThemesResponse(string $themeName): array
{
    return [
        'data' => [
            'themes' => [
                'nodes' => [
                    ['id' => 'gid://shopify/OnlineStoreTheme/99999', 'name' => $themeName, 'role' => 'MAIN'],
                ],
            ],
        ],
    ];
}

function brandDesignFakeAssetResponse(array $currentSettings): array
{
    return [
        'asset' => [
            'key' => 'config/settings_data.json',
            'value' => json_encode(['current' => $currentSettings, 'presets' => []]),
        ],
    ];
}

// Standard 3-endpoint fake: brand graphql, themes graphql (same URL, routed by
// query body), asset REST. Caller picks theme name + the settings keys present.
function fakeBrandDesignImporterShopify(string $themeName, array $currentSettings): void
{
    Http::fake([
        'importer.myshopify.com/admin/api/*/graphql.json' => function ($request) use ($themeName) {
            $query = $request->data()['query'] ?? '';
            if (str_contains($query, 'shop {')) {
                return Http::response(brandDesignFakeShopBrandResponse());
            }
            if (str_contains($query, 'themes(')) {
                return Http::response(brandDesignFakeThemesResponse($themeName));
            }

            return Http::response(['errors' => [['message' => 'unexpected query']]], 500);
        },
        'importer.myshopify.com/admin/api/*/themes/*/assets.json*' => Http::response(
            brandDesignFakeAssetResponse($currentSettings)
        ),
    ]);
}

it('imports the full brand design shape from a populated Shopify response', function () {
    $integration = makeBrandDesignImporterIntegration();
    fakeBrandDesignImporterShopify('Dawn 13.0.0', [
        'buttons_radius' => 8,            // → default
        'buttons_border_thickness' => 3,  // → default
        'spacing_sections' => 80,         // → spacious
    ]);

    $imported = app(BrandDesignImporter::class)->import($integration);

    // background/text/border are no longer brand-picked — only accent stays.
    // theme_mode is inferred from the merchant's primary background colour.
    expect($imported['colors'])->toEqual([
        'accent' => '#ff0066',
    ]);
    expect($imported['theme_mode'])->toBe('light'); // primary bg #ffffff → light
    expect($imported['corner_radius'])->toBe('default');
    expect($imported['border_thickness'])->toBe('default');
    expect($imported['section_spacing'])->toBe('spacious');
    expect($imported['logo']['full_url'])->toBe('https://cdn.shopify.com/s/files/full-logo.png');
    expect($imported['logo']['square_url'])->toBe('https://cdn.shopify.com/s/files/square-logo.png');
    expect($imported['slogan'])->toBe('Fresh and clean');
    expect($imported['shop_gid'])->toBe('gid://shopify/Shop/12345');
});

it('buckets corner_radius at the documented thresholds', function (int $px, string $expected) {
    $integration = makeBrandDesignImporterIntegration();
    fakeBrandDesignImporterShopify('Dawn', ['buttons_radius' => $px]);

    $imported = app(BrandDesignImporter::class)->import($integration);

    expect($imported['corner_radius'])->toBe($expected);
})->with([
    'lower bound of square' => [0, 'square'],
    'top of square' => [4, 'square'],
    'bottom of default' => [5, 'default'],
    'top of default' => [16, 'default'],
    'bottom of pill' => [17, 'pill'],
    'large pill' => [99, 'pill'],
]);

it('buckets border_thickness at the documented thresholds', function (int $px, string $expected) {
    $integration = makeBrandDesignImporterIntegration();
    fakeBrandDesignImporterShopify('Dawn', ['buttons_border_thickness' => $px]);

    $imported = app(BrandDesignImporter::class)->import($integration);

    expect($imported['border_thickness'])->toBe($expected);
})->with([
    'lower bound of hairline' => [0, 'hairline'],
    'top of hairline' => [1, 'hairline'],
    'bottom of default' => [2, 'default'],
    'top of default' => [3, 'default'],
    'bottom of bold' => [4, 'bold'],
    'large bold' => [10, 'bold'],
]);

it('buckets section_spacing at the documented thresholds', function (int $px, string $expected) {
    $integration = makeBrandDesignImporterIntegration();
    fakeBrandDesignImporterShopify('Dawn', ['spacing_sections' => $px]);

    $imported = app(BrandDesignImporter::class)->import($integration);

    expect($imported['section_spacing'])->toBe($expected);
})->with([
    'lower bound of tight' => [0, 'tight'],
    'top of tight' => [32, 'tight'],
    'bottom of default' => [33, 'default'],
    'top of default' => [64, 'default'],
    'bottom of spacious' => [65, 'spacious'],
    'large spacious' => [200, 'spacious'],
]);

it('falls through to generic theme hints when the theme name is unknown', function () {
    $integration = makeBrandDesignImporterIntegration();

    // `border_radius` is in the GENERIC hint list but not in any named theme
    // (Dawn/Horizon/etc.). If "Some Random Theme" correctly falls through to
    // generic, the importer finds the value; otherwise corner_radius is null.
    fakeBrandDesignImporterShopify('Some Random Theme', [
        'border_radius' => 20,
    ]);

    $imported = app(BrandDesignImporter::class)->import($integration);

    expect($imported['corner_radius'])->toBe('pill');
});

it('throws RuntimeException on a non-Shopify shop_domain', function () {
    $integration = makeBrandDesignImporterIntegration('evil.example.com');

    // No HTTP fakes — the regex guard should fire before any HTTP is attempted.
    Http::preventStrayRequests();

    expect(fn () => app(BrandDesignImporter::class)->import($integration))
        ->toThrow(\RuntimeException::class, 'Invalid Shopify credentials');
});

it('returns null enums (without throwing) when the asset endpoint fails', function () {
    $integration = makeBrandDesignImporterIntegration();

    Http::fake([
        'importer.myshopify.com/admin/api/*/graphql.json' => function ($request) {
            $query = $request->data()['query'] ?? '';
            if (str_contains($query, 'shop {')) {
                return Http::response(brandDesignFakeShopBrandResponse());
            }

            return Http::response(brandDesignFakeThemesResponse('Dawn'));
        },
        'importer.myshopify.com/admin/api/*/themes/*/assets.json*' => Http::response(null, 404),
    ]);

    $imported = app(BrandDesignImporter::class)->import($integration);

    expect($imported['corner_radius'])->toBeNull();
    expect($imported['border_thickness'])->toBeNull();
    expect($imported['section_spacing'])->toBeNull();
    // Brand fields still come through despite the asset failure.
    expect($imported['colors']['accent'])->toBe('#ff0066');
    expect($imported['theme_mode'])->toBe('light');
    expect($imported['logo']['full_url'])->toBe('https://cdn.shopify.com/s/files/full-logo.png');
});

it('infers theme_mode dark when the primary background is a dark hue', function () {
    $integration = makeBrandDesignImporterIntegration();

    Http::fake([
        'importer.myshopify.com/admin/api/*/graphql.json' => function ($request) {
            $query = $request->data()['query'] ?? '';
            if (str_contains($query, 'shop {')) {
                return Http::response([
                    'data' => [
                        'shop' => [
                            'id' => 'gid://shopify/Shop/12345',
                            'myshopifyDomain' => 'importer.myshopify.com',
                            'brand' => [
                                'slogan' => null,
                                'logo' => ['image' => ['url' => null]],
                                'squareLogo' => ['image' => ['url' => null]],
                                'colors' => [
                                    'primary' => [['background' => '#0a0a0a', 'foreground' => '#ffffff']],
                                    'secondary' => [['background' => '#ff0066', 'foreground' => '#ffffff']],
                                ],
                            ],
                        ],
                    ],
                ]);
            }
            return Http::response(brandDesignFakeThemesResponse('Dawn'));
        },
        'importer.myshopify.com/admin/api/*/themes/*/assets.json*' => Http::response(
            brandDesignFakeAssetResponse([])
        ),
    ]);

    $imported = app(BrandDesignImporter::class)->import($integration);

    expect($imported['theme_mode'])->toBe('dark');
});

it('resolves a settings_data.json preset when current is a string', function () {
    $integration = makeBrandDesignImporterIntegration();

    // settings_data.json `current` is sometimes a preset name string instead
    // of an object — the importer must look the name up under `presets`.
    Http::fake([
        'importer.myshopify.com/admin/api/*/graphql.json' => function ($request) {
            $query = $request->data()['query'] ?? '';
            if (str_contains($query, 'shop {')) {
                return Http::response(brandDesignFakeShopBrandResponse());
            }

            return Http::response(brandDesignFakeThemesResponse('Dawn'));
        },
        'importer.myshopify.com/admin/api/*/themes/*/assets.json*' => Http::response([
            'asset' => [
                'key' => 'config/settings_data.json',
                'value' => json_encode([
                    'current' => 'mypreset',
                    'presets' => [
                        'mypreset' => ['buttons_radius' => 30],
                    ],
                ]),
            ],
        ]),
    ]);

    $imported = app(BrandDesignImporter::class)->import($integration);

    expect($imported['corner_radius'])->toBe('pill');
});
