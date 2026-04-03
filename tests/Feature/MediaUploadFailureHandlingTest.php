<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Http\Requests\Api\Professional\Uploads\UploadImageRequest;
use App\Jobs\DeleteMediaArtifactsJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    bootstrapMediaUploadFailureSchema();

    config([
        'sidest.media_disk' => 'local',
        'filesystems.disks.local.root' => storage_path('framework/testing/disks/media-upload-failure'),
    ]);

    if (! is_dir(config('filesystems.disks.local.root'))) {
        mkdir(config('filesystems.disks.local.root'), 0777, true);
    }

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull();
    app()->instance(SiteCacheService::class, $cache);
});

it('dispatches video cleanup with directory base path when deleting media', function () {
    Queue::fake();

    [$professional] = createProfessionalAndSiteForMediaUploadTests();

    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site_media')->insert([
        'id' => $mediaId,
        'site_id' => $professional->site->id,
        'pool' => 'gallery',
        'path' => "videos/{$professional->id}/{$mediaId}/original_abc123.mp4",
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_VIDEO,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $request = Request::create("/api/images/{$mediaId}", 'DELETE');
    $request->attributes->set('professional', $professional);
    app()->instance('request', $request);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $controller = new ProfessionalUploadController($mediaService);
    $siteImage = SiteMedia::query()->findOrFail($mediaId);

    $response = $controller->destroy($siteImage);

    expect($response->getStatusCode())->toBe(200);

    Queue::assertPushed(DeleteMediaArtifactsJob::class, function (DeleteMediaArtifactsJob $job) use ($professional, $mediaId) {
        return $job->mediaId === $mediaId
            && $job->basePath === "videos/{$professional->id}/{$mediaId}"
            && $job->pool === 'gallery';
    });

    $deleted = SiteMedia::withTrashed()->findOrFail($mediaId);
    expect($deleted->trashed())->toBeTrue();
});

it('returns 503 and soft-deletes media when video dispatch fails', function () {
    [$professional] = createProfessionalAndSiteForMediaUploadTests();

    config([
        'queue.default' => 'database',
        'sidest.video_queue.connection' => 'missing_video_connection',
        'sidest.video_uploads_enabled' => true,
    ]);

    app()->instance('env', 'production');

    $video = UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4');
    $baseRequest = Request::create('/api/uploads', 'POST', [
        'pool' => 'gallery',
        'alt_text' => 'Clip',
    ], [], [
        'video' => $video,
    ]);

    /** @var UploadImageRequest $request */
    $request = UploadImageRequest::createFromBase($baseRequest);
    $request->attributes->set('professional', $professional);

    $validator = Mockery::mock(Validator::class);
    $validator->shouldReceive('validated')->andReturn([
        'pool' => 'gallery',
        'alt_text' => 'Clip',
    ]);
    $request->setValidator($validator);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $mediaService->shouldReceive('resolvedDiskName')->andReturn('local');

    $controller = new ProfessionalUploadController($mediaService);
    $response = $controller->upload($request);

    expect($response->getStatusCode())->toBe(503);
    expect($response->getData(true)['message'] ?? null)
        ->toBe('Video processing is temporarily unavailable. Please try again.');

    $media = SiteMedia::withTrashed()->latest('created_at')->first();
    expect($media)->not->toBeNull();
    expect($media->trashed())->toBeTrue();
    expect(SiteMedia::query()->count())->toBe(0);
    expect(Storage::disk('local')->exists($media->path))->toBeFalse();
});

function bootstrapMediaUploadFailureSchema(): void
{
    $sqlite = config('database.connections.sqlite');

    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $db = DB::connection('pgsql');

    $db->statement('CREATE TABLE IF NOT EXISTS professionals (
        id TEXT PRIMARY KEY,
        professional_type TEXT NULL,
        display_name TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $db->statement('CREATE TABLE IF NOT EXISTS sites (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        subdomain TEXT NULL,
        is_published INTEGER NULL,
        settings TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $db->statement('CREATE TABLE IF NOT EXISTS site_media (
        id TEXT PRIMARY KEY,
        site_id TEXT NOT NULL,
        pool TEXT NOT NULL,
        path TEXT NOT NULL,
        alt_text TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        media_type TEXT NOT NULL DEFAULT \'image\',
        processing_state TEXT NOT NULL DEFAULT \'pending\',
        original_mime TEXT NULL,
        original_size_bytes INTEGER NULL,
        duration_ms INTEGER NULL,
        poster_path TEXT NULL,
        processing_error TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * @return array{0: Professional, 1: Site}
 */
function createProfessionalAndSiteForMediaUploadTests(): array
{
    $professionalId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('professionals')->insert([
        'id' => $professionalId,
        'professional_type' => 'professional',
        'display_name' => 'Test Professional',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('sites')->insert([
        'id' => $siteId,
        'professional_id' => $professionalId,
        'subdomain' => 'test-pro',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $professional = Professional::query()->findOrFail($professionalId);
    $professional->load('site');
    $site = Site::query()->findOrFail($siteId);

    return [$professional, $site];
}
