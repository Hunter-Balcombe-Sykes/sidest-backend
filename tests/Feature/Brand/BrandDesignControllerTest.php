<?php

use App\Http\Controllers\Api\Professional\Store\BrandDesignController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// These tests exercise the surface behaviour of the unified brand-design
// controller — the old override/reset endpoints were retired. The show
// endpoint reads exclusively from site.settings.design (the new unified
// shape seeded by the unify_brand_design_storage migration).
function makeBrandDesignRequest(string $method = 'GET', array $params = [], ?string $type = 'brand'): Request
{
    $professional = new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => $type,
        'status' => 'active',
    ]);

    $request = Request::create('/api/test', $method, $params);
    $request->attributes->set('professional', $professional);

    return $request;
}

it('returns 403 when non-brand tries to view design', function () {
    $controller = app(BrandDesignController::class);
    $response = $controller->show(makeBrandDesignRequest('GET', [], 'influencer'));

    expect($response->status())->toBe(403);
    expect($response->getData(true)['message'])->toContain('brand accounts');
});

it('returns 403 when non-brand tries to resync design', function () {
    $controller = app(BrandDesignController::class);
    $response = $controller->resync(makeBrandDesignRequest('POST', [], 'influencer'));

    expect($response->status())->toBe(403);
});

it('returns logo urls + placeholders from site_media on show', function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        access_token TEXT NULL,
        provider_metadata TEXT NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    $brandId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandId,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => 'designlogotest',
        'handle_lc' => 'designlogotest',
        'display_name' => 'DesignLogoTest',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Settings hold tokens (colors, font, enums) only — logo lives in site_media.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $brandId,
        'subdomain' => 'designlogotest',
        'settings' => json_encode([
            'design' => [
                'colors' => [
                    'background' => '#ffffff',
                    'text' => '#000000',
                    'accent' => '#ff0000',
                    'border' => null,
                ],
                'font_family' => 'helvetica_neue',
                'corner_radius' => 'default',
                'border_thickness' => 'default',
                'section_spacing' => 'default',
            ],
        ]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Seed two logo rows + two placeholder rows in site_media, plus matching
    // media_variants so listDesignMedia returns non-null URLs.
    $logoFullId = (string) Str::uuid();
    $logoSquareId = (string) Str::uuid();
    $placeholderAId = (string) Str::uuid();
    $placeholderBId = (string) Str::uuid();

    foreach ([
        [$logoFullId, 'logo_full', 0],
        [$logoSquareId, 'logo_square', 0],
        [$placeholderAId, 'placeholder', 0],
        [$placeholderBId, 'placeholder', 1],
    ] as [$id, $purpose, $sortOrder]) {
        DB::connection('pgsql')->table('site.site_media')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'pool' => 'design',
            'purpose' => $purpose,
            'path' => "images/{$brandId}/{$id}/original.png",
            'alt_text' => $purpose === 'placeholder' ? "{$id}.png" : null,
            'sort_order' => $sortOrder,
            'is_active' => 1,
            'media_type' => 'image',
            'processing_state' => 'ready',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('pgsql')->table('site.media_variants')->insert([
            'id' => (string) Str::uuid(),
            'media_id' => $id,
            'variant_key' => 'optimized',
            'artifact_type' => 'webp',
            'disk' => 'media',
            'path' => "images/{$brandId}/{$id}/optimized.webp",
            'mime' => 'image/webp',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    Storage::fake('media');

    $brand = Professional::query()->findOrFail($brandId);
    $brand->setRelation('site', Site::query()->findOrFail($siteId));

    $request = Request::create('/api/brand/design', 'GET');
    $request->attributes->set('professional', $brand);

    $controller = app(BrandDesignController::class);
    $response = $controller->show($request);
    $data = $response->getData(true);

    $payload = $data['data'] ?? $data;

    expect($response->status())->toBe(200);
    expect($payload['logo']['full_url'])->not->toBeNull();
    expect($payload['logo']['square_url'])->not->toBeNull();
    expect($payload['colors']['accent'])->toBe('#ff0000');
    expect($payload['font_family'])->toBe('helvetica_neue');
    expect($payload['placeholders'])->toHaveCount(2);
    expect($payload['placeholders'][0]['sort_order'])->toBe(0);
    expect($payload['placeholders'][1]['sort_order'])->toBe(1);
});
