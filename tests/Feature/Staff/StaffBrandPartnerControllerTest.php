<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandPartnerController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (\Throwable) {
    }
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS site");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY, handle TEXT, display_name TEXT, professional_type TEXT,
        status TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY, professional_id TEXT, subdomain TEXT,
        settings TEXT, is_published INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT
    )');
});

function staffPromoteFixture(string $affiliateType = 'professional'): array
{
    $affId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $affId,
        'professional_type' => $affiliateType,
        'status' => 'active',
    ]);

    $brandId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $brandId,
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    DB::table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affId,
        'subdomain' => 'aff',
    ]);

    return [
        'affiliate' => Professional::find($affId),
        'brand' => Professional::find($brandId),
    ];
}

it('refuses to promote when the caller is a brand-type professional', function () {
    $fix = staffPromoteFixture('brand');
    $controller = new StaffBrandPartnerController;

    $links = Mockery::mock(BrandPartnerLinkService::class);
    $links->shouldNotReceive('promoteBrandToPrimary');
    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);

    $response = $controller->promote(Request::create('/', 'POST'), $fix['affiliate'], $fix['brand'], $links, $sync);

    expect($response->status())->toBe(422);
});

it('returns 404 when the affiliate has no site', function () {
    $affId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $affId, 'professional_type' => 'professional', 'status' => 'active',
    ]);
    $brandId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $brandId, 'professional_type' => 'brand', 'status' => 'active',
    ]);

    $controller = new StaffBrandPartnerController;
    $links = Mockery::mock(BrandPartnerLinkService::class);
    $links->shouldNotReceive('promoteBrandToPrimary');
    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);

    $response = $controller->promote(
        Request::create('/', 'POST'),
        Professional::find($affId),
        Professional::find($brandId),
        $links,
        $sync,
    );

    expect($response->status())->toBe(404);
});

it('returns 404 when the brand is not in additional partners', function () {
    $fix = staffPromoteFixture();
    $controller = new StaffBrandPartnerController;

    $links = Mockery::mock(BrandPartnerLinkService::class);
    $links->shouldReceive('promoteBrandToPrimary')
        ->once()
        ->with($fix['affiliate']->id, $fix['brand']->id)
        ->andReturn(false);
    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldNotReceive('sync');

    $response = $controller->promote(Request::create('/', 'POST'), $fix['affiliate'], $fix['brand'], $links, $sync);

    expect($response->status())->toBe(404);
});

it('promotes the brand and syncs the affiliate site', function () {
    $fix = staffPromoteFixture();
    $controller = new StaffBrandPartnerController;

    $links = Mockery::mock(BrandPartnerLinkService::class);
    $links->shouldReceive('promoteBrandToPrimary')
        ->once()
        ->with($fix['affiliate']->id, $fix['brand']->id)
        ->andReturn(true);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once()->withArgs(fn (Site $site, string $proId) => $site->professional_id === $fix['affiliate']->id && $proId === $fix['affiliate']->id);
    $sync->shouldReceive('invalidateAffiliateCaches')->once();

    $response = $controller->promote(Request::create('/', 'POST'), $fix['affiliate'], $fix['brand'], $links, $sync);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['promoted'])->toBeTrue()
        ->and($data['affiliate_professional_id'])->toBe($fix['affiliate']->id)
        ->and($data['primary_professional_id'])->toBe($fix['brand']->id);
});
