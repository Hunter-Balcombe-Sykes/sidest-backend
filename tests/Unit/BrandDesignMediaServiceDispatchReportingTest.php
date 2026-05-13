<?php

use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\BrandDesignMediaService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(Tests\TestCase::class)->in(__FILE__);

// Verifies that when ProcessImageVariantsJob::dispatchSync() throws in inline
// mode (local/testing env), the exception is reported via report($e) AND the
// SiteMedia row is marked as failed (so the row doesn't stay stuck in
// 'processing' forever — the queue's failed() callback doesn't fire for sync).
//
// Before the fix: Log::error only, no report($e), row stays in 'processing'.
// After: report($e) + forceFill(processing_state=failed).

beforeEach(function () {
    setupSitesTable();
    setupMediaTables();

    Storage::fake('media');

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull()->byDefault();
    $cache->shouldReceive('forgetBrandDesign')->andReturnNull()->byDefault();
    app()->instance(SiteCacheService::class, $cache);
});

function dispatchReport_makeSite(): Site
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'test-'.substr($siteId, 0, 8),
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Site::query()->findOrFail($siteId);
}

it('reports the exception and marks SiteMedia as failed when dispatchSync throws in inline mode', function () {
    Exceptions::fake();

    $site = dispatchReport_makeSite();

    // ImageVariantService mock: storeOriginal succeeds (returns a path), but
    // resolvedDiskName() throws so the job fails when it tries to resolve the disk.
    $imageVariant = Mockery::mock(ImageVariantService::class);
    $imageVariant->shouldReceive('storeOriginal')
        ->andReturnUsing(fn ($file, $basePath) => "{$basePath}/original.png");

    // Bind into the container so the job gets the throwing mock injected
    $imageVariant->shouldReceive('resolvedDiskName')
        ->andThrow(new \RuntimeException('Media disk not configured'));
    app()->instance(ImageVariantService::class, $imageVariant);

    $service = new BrandDesignMediaService($imageVariant);

    // Upload a logo — triggers dispatchVariantJob internally
    Storage::fake('media');
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    $media = $service->upsertLogoFromUploadedFile($site, (string) Str::uuid(), $file, 'full');

    // The row must be marked failed (not stuck in 'processing')
    $fresh = SiteMedia::find($media->id);
    expect($fresh->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);

    // And the exception must be reported to Nightwatch
    Exceptions::assertReported(\RuntimeException::class);
});
