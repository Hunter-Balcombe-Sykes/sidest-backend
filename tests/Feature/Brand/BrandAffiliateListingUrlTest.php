<?php

// Tests that the brand affiliate listing endpoint (GET /api/brand-affiliates)
// exposes site_url from brand.brand_partner_links for each linked affiliate.

use App\Http\Controllers\Api\Professional\Brand\BrandAffiliateController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();
    setupSitesTable();

    // brand.brand_partner_links with site_url column (trigger-managed in production).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        slot INTEGER NULL,
        custom_photos_enabled INTEGER NULL,
        site_url TEXT NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');
});

function affiliateListingSeedTenants(?string $siteUrl = null): array
{
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        [
            'id' => $brandId,
            'handle' => 'brand-co',
            'handle_lc' => 'brand-co',
            'display_name' => 'Brand Co',
            'first_name' => 'Brand',
            'last_name' => 'Co',
            'primary_email' => 'brand@example.test',
            'professional_type' => 'brand',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $affiliateId,
            'handle' => 'jane',
            'handle_lc' => 'jane',
            'display_name' => 'Jane Doe',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'primary_email' => 'jane@example.test',
            'professional_type' => 'professional',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $affiliateId,
        'subdomain' => 'jane',
        'is_published' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'slot' => 0,
        'custom_photos_enabled' => 0,
        'site_url' => $siteUrl,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$brandId, $affiliateId];
}

function affiliateListingMakeRequest(string $brandId): Request
{
    $brand = Professional::query()->whereKey($brandId)->first()
        ?? (new Professional)->forceFill(['id' => $brandId, 'professional_type' => 'brand']);

    $request = Request::create('/api/brand-affiliates', 'GET');
    $request->attributes->set('professional', $brand);

    return $request;
}

it('exposes site_url from brand_partner_links in the affiliate listing', function () {
    $expectedUrl = 'https://brand-co.partna.au/jane';
    [$brandId] = affiliateListingSeedTenants($expectedUrl);

    $controller = app(BrandAffiliateController::class);
    $response = $controller->index(affiliateListingMakeRequest($brandId));

    expect($response->status())->toBe(200);

    $data = json_decode($response->getContent(), true);
    expect($data['affiliates'])->toHaveCount(1);

    $affiliate = $data['affiliates'][0];
    expect($affiliate)->toHaveKey('site_url')
        ->and($affiliate['site_url'])->toBe($expectedUrl);
});

it('returns site_url as null when not yet set on the link', function () {
    [$brandId] = affiliateListingSeedTenants(null);

    $controller = app(BrandAffiliateController::class);
    $response = $controller->index(affiliateListingMakeRequest($brandId));

    expect($response->status())->toBe(200);

    $data = json_decode($response->getContent(), true);
    expect($data['affiliates'])->toHaveCount(1);
    expect($data['affiliates'][0])->toHaveKey('site_url')
        ->and($data['affiliates'][0]['site_url'])->toBeNull();
});
