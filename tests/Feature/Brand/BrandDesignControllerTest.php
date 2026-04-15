<?php

use App\Http\Controllers\Api\Professional\Store\BrandDesignController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    $controller = new BrandDesignController();
    $response = $controller->show(makeBrandDesignRequest('GET', [], 'influencer'));

    expect($response->status())->toBe(403);
    expect($response->getData(true)['message'])->toContain('brand accounts');
});

it('returns 403 when non-brand tries to resync design', function () {
    $controller = new BrandDesignController();
    $response = $controller->resync(makeBrandDesignRequest('POST', [], 'influencer'));

    expect($response->status())->toBe(403);
});

it('returns logo urls from the unified design shape on show', function () {
    setupProfessionalsTable();
    setupSitesTable();

    // BrandDesignController::show queries professional_integrations to
    // determine shopify_connected — create the table so the query doesn't fail.
    // attachTestSchemas() (called by setupProfessionalsTable) already ATTACHes
    // the 'core' schema as an in-memory SQLite database, so we can CREATE TABLE
    // directly under core.* without needing a separate ATTACH step.
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
    $siteId  = (string) Str::uuid();
    $now     = now()->toDateTimeString();
    $fullUrl = 'https://cdn.example.com/images/full-logo.webp';
    $sqUrl   = 'https://cdn.example.com/images/square-logo.webp';

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id'              => $brandId,
        'auth_user_id'    => 'auth-' . Str::random(8),
        'handle'          => 'designlogotest',
        'handle_lc'       => 'designlogotest',
        'display_name'    => 'DesignLogoTest',
        'professional_type' => 'brand',
        'status'          => 'active',
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    // Seed the unified design shape — mirrors what the
    // unify_brand_design_storage migration does for existing rows.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id'              => $siteId,
        'professional_id' => $brandId,
        'subdomain'       => 'designlogotest',
        'settings'        => json_encode([
            'design' => [
                'logo' => [
                    'full_url'   => $fullUrl,
                    'square_url' => $sqUrl,
                ],
                'colors' => [
                    'background' => '#ffffff',
                    'text'       => '#000000',
                    'accent'     => '#ff0000',
                    'border'     => null,
                ],
                'font_family'      => 'helvetica_neue',
                'corner_radius'    => 'default',
                'border_thickness' => 'default',
                'section_spacing'  => 'default',
            ],
        ]),
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    $brand = Professional::query()->findOrFail($brandId);
    $brand->setRelation('site', Site::query()->findOrFail($siteId));

    $request = Request::create('/api/brand/design', 'GET');
    $request->attributes->set('professional', $brand);

    $controller = new BrandDesignController();
    $response   = $controller->show($request);
    $data       = $response->getData(true);

    // The response is wrapped in a JsonResource { data: {...} } envelope.
    $payload = $data['data'] ?? $data;

    expect($response->status())->toBe(200);
    expect($payload['logo']['full_url'])->toBe($fullUrl);
    expect($payload['logo']['square_url'])->toBe($sqUrl);
    expect($payload['colors']['background'])->toBe('#ffffff');
    expect($payload['font_family'])->toBe('helvetica_neue');
});
