<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Http\Requests\Api\Professional\Uploads\UploadBrandLogoRequest;
use App\Http\Requests\Api\Professional\Uploads\UploadBrandPlaceholderImageRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Regression coverage for the brand design pool refactor — see
 * supabase/migrations/20260414100000_site_media_design_pool.sql.
 *
 * The original bug: brand logo / placeholder uploads went into POOL_CONTENT
 * with a hardcoded sort_order=0, and the legacy unique index
 * (site_id, sort_order) WHERE deleted_at IS NULL forced every non-deleted
 * row across every pool to share one sort_order namespace per site. Result:
 * the second design-image upload (logo + placeholder, or a logo re-upload,
 * or any pre-existing image at sort_order=0) raised a 23505 unique violation.
 *
 * After the refactor, design assets live in their own pool, the unique
 * sort_order index is scoped per pool, and storeBrandDesignImage replaces
 * any prior row with the same alt_text inside a transaction.
 */

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();

    Storage::fake('media');
    Bus::fake();

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull();
    app()->instance(SiteCacheService::class, $cache);
});

function createBrandWithSiteForDesignUpload(string $handle = 'designbrand'): array
{
    $brandId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandId,
        'auth_user_id' => 'auth-' . Str::random(8),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $brandId,
        'subdomain' => $handle,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $brand = Professional::query()->findOrFail($brandId);
    $brand->load('site');

    return [$brand, Site::query()->findOrFail($siteId)];
}

function makeBrandLogoRequest(Professional $brand): UploadBrandLogoRequest
{
    $logo = UploadedFile::fake()->image('logo.png', 256, 256);
    $base = Request::create('/api/uploads/brand-logo', 'POST', [], [], ['logo' => $logo]);

    /** @var UploadBrandLogoRequest $request */
    $request = UploadBrandLogoRequest::createFromBase($base);
    $request->attributes->set('professional', $brand);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn([]);
    $request->setValidator($validator);

    return $request;
}

function makeBrandPlaceholderRequest(Professional $brand): UploadBrandPlaceholderImageRequest
{
    $image = UploadedFile::fake()->image('placeholder.png', 256, 256);
    $base = Request::create('/api/uploads/brand-placeholder-image', 'POST', [], [], ['image' => $image]);

    /** @var UploadBrandPlaceholderImageRequest $request */
    $request = UploadBrandPlaceholderImageRequest::createFromBase($base);
    $request->attributes->set('professional', $brand);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn([]);
    $request->setValidator($validator);

    return $request;
}

function makeMockedUploadController(): ProfessionalUploadController
{
    $mediaService = Mockery::mock(ImageVariantService::class);
    // storeOriginal is invoked once per upload — return a deterministic path
    // derived from the basePath the controller computes.
    $mediaService->shouldReceive('storeOriginal')
        ->andReturnUsing(fn ($file, $basePath) => "{$basePath}/original.png");
    $mediaService->shouldReceive('resolvedDiskName')->andReturn('media');

    $brandDesign = new \App\Services\Media\BrandDesignMediaService($mediaService);

    return new ProfessionalUploadController($mediaService, $brandDesign);
}

it('stores a brand logo in the design pool', function () {
    [$brand, $site] = createBrandWithSiteForDesignUpload('logobrand');

    $controller = makeMockedUploadController();
    $response = $controller->uploadBrandLogo(makeBrandLogoRequest($brand));

    expect($response->getStatusCode())->toBe(201);

    $rows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->whereNull('deleted_at')
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->pool)->toBe(SiteMedia::POOL_DESIGN);
    expect($rows->first()->purpose)->toBe(SiteMedia::PURPOSE_LOGO_FULL);
});

it('replaces the previous logo on re-upload instead of crashing', function () {
    [$brand, $site] = createBrandWithSiteForDesignUpload('rebrand');

    $controller = makeMockedUploadController();

    $first = $controller->uploadBrandLogo(makeBrandLogoRequest($brand));
    $second = $controller->uploadBrandLogo(makeBrandLogoRequest($brand));

    expect($first->getStatusCode())->toBe(201);
    expect($second->getStatusCode())->toBe(201);

    // Exactly one active logo row per site — the previous one is soft-deleted.
    $activeLogos = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
        ->whereNull('deleted_at')
        ->get();

    expect($activeLogos)->toHaveCount(1);

    // The first row was soft-deleted, not hard-deleted (30-day retention).
    $allLogos = SiteMedia::withTrashed()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
        ->get();

    expect($allLogos)->toHaveCount(2);
    expect($allLogos->whereNotNull('deleted_at'))->toHaveCount(1);
});

it('allows uploading both a logo and a placeholder for the same site', function () {
    [$brand, $site] = createBrandWithSiteForDesignUpload('comboBrand');

    $controller = makeMockedUploadController();

    $logoResponse = $controller->uploadBrandLogo(makeBrandLogoRequest($brand));
    $placeholderResponse = $controller->uploadBrandPlaceholderImage(makeBrandPlaceholderRequest($brand));

    expect($logoResponse->getStatusCode())->toBe(201);
    expect($placeholderResponse->getStatusCode())->toBe(201);

    $designRows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->whereNull('deleted_at')
        ->get();

    expect($designRows)->toHaveCount(2);
    expect($designRows->pluck('purpose')->sort()->values()->all())
        ->toBe([SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_PLACEHOLDER]);
});

it('uploads a brand logo even when a content-pool image already occupies sort_order 0', function () {
    // This is the original failing scenario: the legacy unique index spanned
    // every pool, so any pre-existing image at sort_order=0 (in any pool)
    // blocked design uploads. The pool-scoped index fixes it.
    [$brand, $site] = createBrandWithSiteForDesignUpload('coexistbrand');

    SiteMedia::create([
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_CONTENT,
        'path' => 'images/test/existing.jpg',
        'alt_text' => 'gallery item',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
    ]);

    $controller = makeMockedUploadController();
    $response = $controller->uploadBrandLogo(makeBrandLogoRequest($brand));

    expect($response->getStatusCode())->toBe(201);

    $rows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->whereNull('deleted_at')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows->where('pool', SiteMedia::POOL_CONTENT))->toHaveCount(1);
    expect($rows->where('pool', SiteMedia::POOL_DESIGN))->toHaveCount(1);
});

it('rejects brand logo uploads from non-brand professionals', function () {
    [$brand, $site] = createBrandWithSiteForDesignUpload('typecheck');

    // Flip the type on the existing professional row.
    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $brand->id)
        ->update(['professional_type' => 'professional']);

    $brand = Professional::query()->findOrFail($brand->id);
    $brand->load('site');

    $controller = makeMockedUploadController();
    $response = $controller->uploadBrandLogo(makeBrandLogoRequest($brand));

    expect($response->getStatusCode())->toBe(403);
    expect(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('exposes the design pool constant', function () {
    expect(SiteMedia::POOL_DESIGN)->toBe('design');
});
