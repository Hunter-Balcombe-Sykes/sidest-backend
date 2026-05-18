<?php

// Master Pattern 22 — race-safety hardening (LIFE-D#1, LIFE-D#10, LIFE-D#11,
// LIFE-A#1). Each test pins a specific observable property of the fix so a
// future refactor cannot silently regress the race protections. Concurrency
// itself is not exercised here (SQLite-in-memory has no row-locking) — the
// tests instead assert the surface contracts that make the locks effective.

use App\Jobs\ProcessImageVariantsJob;
use App\Jobs\ProcessVideoVariantsJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\BrandDesignMediaService;
use App\Services\Media\ImageVariantService;
use App\Services\Media\VideoVariantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupSitesTable();
    setupMediaTables();

    Storage::fake('media');
    Bus::fake();

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull()->byDefault();
    $cache->shouldReceive('forgetBrandDesign')->andReturnNull()->byDefault();
    app()->instance(SiteCacheService::class, $cache);
});

function rsh_makeBrandSite(): Site
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'rsh-'.substr($siteId, 0, 8),
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Site::query()->findOrFail($siteId);
}

function rsh_makeService(): BrandDesignMediaService
{
    $imageVariant = Mockery::mock(ImageVariantService::class);
    $imageVariant->shouldReceive('storeOriginal')
        ->andReturnUsing(fn ($file, $basePath) => "{$basePath}/original_aaaaaaaaaaaaaaaa.png");
    $imageVariant->shouldReceive('resolvedDiskName')->andReturn('media');

    return new BrandDesignMediaService($imageVariant);
}

// ─── Step 4 — Logo upload row-then-file ordering (LIFE-D#11) ──────────────

it('persists logo path at insert time, not via a follow-up update', function () {
    // Before the fix, createDesignRow inserted with path='' and the caller
    // ran $media->update(['path' => ...]) AFTER storeOriginal returned. A
    // concurrent upsert mid-flight could soft-delete the row, making that
    // update silently no-op. After the fix, the row commits with path
    // already set — we assert that by spying on the freshly returned row
    // BEFORE any refresh() side-effects.
    $site = rsh_makeBrandSite();
    $row = rsh_makeService()->upsertLogoFromUploadedFile(
        $site,
        $site->professional_id,
        UploadedFile::fake()->image('logo.png', 64, 64),
        'full',
    );

    expect($row->path)->not->toBe('');
    expect($row->path)->toStartWith("images/{$site->professional_id}/by-hash/");
});

it('stores the logo original under a content-hash basePath for dedupe', function () {
    // The fix moves storeOriginal BEFORE the row insert and uses a
    // content-hash basePath (images/{proId}/by-hash/{sha256}). The new layout
    // makes two same-byte uploads share an R2 path and removes the race-y
    // path-update step entirely. This test pins the layout shape so a future
    // refactor cannot silently revert to the row-id basePath.
    $site = rsh_makeBrandSite();
    // Use a real PNG so finfo MIME detection succeeds. Hold the UploadedFile
    // in a variable so its tmpfile is not garbage-collected before read.
    $seed = UploadedFile::fake()->image('seed.png', 32, 32);
    $bytes = file_get_contents($seed->getRealPath());
    $row = rsh_makeService()->upsertLogoFromBytes(
        $site,
        $site->professional_id,
        $bytes,
        'image/png',
        'square',
    );

    expect($row->path)->toMatch("#^images/{$site->professional_id}/by-hash/[a-f0-9]{64}/original_[a-f0-9]{16}\\.png$#");
    expect($row->path)->not->toContain($row->id);
});

// ─── Step 3 — ProcessImageVariantsJob in-flight Redis lock (LIFE-D#10) ────

it('skips ProcessImageVariantsJob when another worker already holds the media lock', function () {
    // Pre-set the lock so Redis::set with NX returns false (lock not acquired).
    // The job must return early WITHOUT flipping processing_state to PROCESSING.
    $imageId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $imageId,
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => '',
        'sort_order' => 0,
        'is_active' => 1,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Redis::shouldReceive('set')
        ->once()
        ->with("image:processing-lock:{$imageId}", '1', 'EX', Mockery::type('int'), 'NX')
        ->andReturn(false);
    Redis::shouldNotReceive('del');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldNotReceive('processVariants');
    $service->shouldNotReceive('resolvedDiskName');

    $job = new ProcessImageVariantsJob("path/{$imageId}/original.jpg", $imageId, "path/{$imageId}");
    $job->handle($service);

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PENDING);
});

it('releases the ProcessImageVariantsJob Redis lock after a successful run', function () {
    // After acquiring the lock and running successfully, the job must
    // Redis::del the key so a redelivery (or a manual retry) is not blocked.
    $imageId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $imageId,
        'site_id' => $siteId,
        'pool' => 'gallery',
        'path' => '',
        'sort_order' => 0,
        'is_active' => 1,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $diskRoot = storage_path('framework/testing/disks/race-safety-image-job');
    if (! is_dir($diskRoot)) {
        mkdir($diskRoot, 0777, true);
    }
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $diskRoot,
    ]);

    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    Redis::shouldReceive('set')
        ->once()
        ->with("image:processing-lock:{$imageId}", '1', 'EX', Mockery::type('int'), 'NX')
        ->andReturn(true);
    Redis::shouldReceive('del')
        ->once()
        ->with("image:processing-lock:{$imageId}");

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturn([]);

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}", $siteId);
    $job->handle($service);
});

it('releases the ProcessImageVariantsJob Redis lock even when the job throws', function () {
    // If processing throws, the finally block must still Redis::del the key
    // so retries can re-acquire after the failure.
    $imageId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $imageId,
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => '',
        'sort_order' => 0,
        'is_active' => 1,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $diskRoot = storage_path('framework/testing/disks/race-safety-image-job-throw');
    if (! is_dir($diskRoot)) {
        mkdir($diskRoot, 0777, true);
    }
    config([
        'partna.media_disk' => 'local',
        'filesystems.disks.local.root' => $diskRoot,
    ]);

    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    Redis::shouldReceive('set')
        ->once()
        ->andReturn(true);
    Redis::shouldReceive('del')
        ->once()
        ->with("image:processing-lock:{$imageId}");

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')
        ->once()
        ->andThrow(new \RuntimeException('processVariants exploded'));

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");

    expect(fn () => $job->handle($service))->toThrow(\RuntimeException::class, 'processVariants exploded');
});

// ─── Step 3 — ProcessVideoVariantsJob in-flight Redis lock (LIFE-D#10) ────

it('skips ProcessVideoVariantsJob when another worker already holds the media lock', function () {
    $mediaId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => '',
        'sort_order' => 0,
        'is_active' => 1,
        'media_type' => 'video',
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Redis::shouldReceive('set')
        ->once()
        ->with("video:processing-lock:{$mediaId}", '1', 'EX', Mockery::type('int'), 'NX')
        ->andReturn(false);
    Redis::shouldNotReceive('del');

    $service = Mockery::mock(VideoVariantService::class);
    $service->shouldNotReceive('processVariants');
    $service->shouldNotReceive('resolvedDiskName');

    $job = new ProcessVideoVariantsJob($mediaId, "videos/test/{$mediaId}/original.mp4", "videos/test/{$mediaId}");
    $job->handle($service);

    $row = SiteMedia::query()->findOrFail($mediaId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PENDING);
});
