<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliatePhotoController;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS commerce");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY, handle TEXT, display_name TEXT, professional_type TEXT,
        status TEXT, about TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY, professional_id TEXT, subdomain TEXT, settings TEXT,
        is_published INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id TEXT PRIMARY KEY, site_id TEXT, pool TEXT, product_gid TEXT, path TEXT,
        alt_text TEXT, sort_order INTEGER, is_active INTEGER DEFAULT 1,
        media_type TEXT, processing_state TEXT, original_mime TEXT,
        original_size_bytes INTEGER, purpose TEXT, deleted_at TEXT,
        created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS site.media_variants (
        id TEXT PRIMARY KEY, media_id TEXT, variant_key TEXT, path TEXT,
        width INTEGER, height INTEGER, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY, affiliate_professional_id TEXT, brand_professional_id TEXT,
        shopify_product_gid TEXT, is_active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT
    )');
});

function seedAffiliatePhotoFixture(string $gid = 'gid://shopify/Product/12345'): array
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->handle = 'aff';
    $pro->display_name = 'Affiliate';
    $pro->professional_type = 'professional';
    $pro->status = 'active';
    DB::table('core.professionals')->insert([
        'id' => $pro->id,
        'handle' => $pro->handle,
        'display_name' => $pro->display_name,
        'professional_type' => $pro->professional_type,
        'status' => $pro->status,
    ]);

    // Site::$fillable omits professional_id, so set it directly. Inserting raw avoids
    // hidden cast surprises in the in-memory schema.
    $siteId = (string) Str::uuid();
    DB::table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $pro->id,
        'subdomain' => 'aff',
    ]);
    $site = Site::find($siteId);

    AffiliateProductSelection::create([
        'affiliate_professional_id' => $pro->id,
        'brand_professional_id' => (string) Str::uuid(),
        'shopify_product_gid' => $gid,
    ]);

    $media = SiteMedia::create([
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_PRODUCT,
        'product_gid' => $gid,
        'path' => "images/{$pro->id}/abc/original.webp",
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
    ]);

    // `find()` lets the controller use loadMissing('site') properly; `$pro->fresh()`
    // doesn't work because we built the row via raw insert, so Eloquent doesn't
    // know it was persisted.
    return ['professional' => Professional::find($pro->id), 'site' => $site, 'media' => $media, 'gid' => $gid];
}

function makeStaffPhotoController(?ImageVariantService $media = null, ?SiteCacheService $cache = null): StaffAffiliatePhotoController
{
    $mediaService = $media ?? Mockery::mock(ImageVariantService::class);
    $cacheService = $cache;
    if ($cacheService === null) {
        $cacheService = Mockery::mock(SiteCacheService::class);
        $cacheService->shouldReceive('invalidateSite')->andReturnNull();
    }

    return new StaffAffiliatePhotoController($mediaService, $cacheService);
}

it('returns 422 for an invalid product GID', function () {
    $pro = new Professional(['id' => (string) Str::uuid()]);
    $controller = makeStaffPhotoController();

    $response = $controller->index(Request::create('/', 'GET'), $pro, 'not-a-gid');

    expect($response->status())->toBe(422);
});

it('returns 404 when the GID is not in the affiliate\'s selections', function () {
    $fixture = seedAffiliatePhotoFixture();
    $controller = makeStaffPhotoController();

    $response = $controller->index(
        Request::create('/', 'GET'),
        $fixture['professional'],
        'gid://shopify/Product/99999'
    );

    expect($response->status())->toBe(404);
});

it('lists active photos for an affiliate\'s product', function () {
    $fixture = seedAffiliatePhotoFixture();
    $controller = makeStaffPhotoController();

    $response = $controller->index(
        Request::create('/', 'GET'),
        $fixture['professional'],
        $fixture['gid']
    );
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['product_gid'])->toBe($fixture['gid'])
        ->and(count($data['images']))->toBe(1)
        ->and($data['images'][0]['id'])->toBe($fixture['media']->id);
});

it('deletes a photo and invalidates the site cache (admin)', function () {
    $fixture = seedAffiliatePhotoFixture();
    $mediaSvc = Mockery::mock(ImageVariantService::class);
    $mediaSvc->shouldReceive('deleteVariants')->once()->with($fixture['media']->id, $fixture['media']->path);

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->once();

    $controller = makeStaffPhotoController($mediaSvc, $cache);

    Log::spy();
    $response = $controller->destroy(
        Request::create('/', 'DELETE'),
        $fixture['professional'],
        $fixture['gid'],
        $fixture['media']->id
    );

    expect($response->status())->toBe(200);

    expect(SiteMedia::query()->where('id', $fixture['media']->id)->exists())->toBeFalse();

    Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) =>
        is_string($msg)
        && str_contains($msg, 'staff-aff-photo-delete')
        && ($ctx['professional_id'] ?? null) === $fixture['professional']->id
    );
});

it('returns 404 when deleting a photo that does not exist', function () {
    $fixture = seedAffiliatePhotoFixture();
    $controller = makeStaffPhotoController();

    $response = $controller->destroy(
        Request::create('/', 'DELETE'),
        $fixture['professional'],
        $fixture['gid'],
        (string) Str::uuid()
    );

    expect($response->status())->toBe(404);
});

it('rejects delete for a GID that is not in the affiliate\'s selections', function () {
    $fixture = seedAffiliatePhotoFixture();
    $controller = makeStaffPhotoController();

    $response = $controller->destroy(
        Request::create('/', 'DELETE'),
        $fixture['professional'],
        'gid://shopify/Product/99999',
        $fixture['media']->id
    );

    expect($response->status())->toBe(404);
});
