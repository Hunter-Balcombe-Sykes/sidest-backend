<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\BrandDesignMediaService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
    app()->instance(SiteCacheService::class, $cache);
});

function makeBrandSite(): Site
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

function makeFakeUpload(string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 256, 256);
}

function makeServiceWithFakeImageVariant(): BrandDesignMediaService
{
    $imageVariant = Mockery::mock(ImageVariantService::class);
    $imageVariant->shouldReceive('storeOriginal')
        ->andReturnUsing(fn ($file, $basePath) => "{$basePath}/original.png");
    $imageVariant->shouldReceive('resolvedDiskName')->andReturn('media');

    return new BrandDesignMediaService($imageVariant);
}

it('upserts a logo_full row from an uploaded file', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $row = $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload(), 'full');

    expect($row->pool)->toBe(SiteMedia::POOL_DESIGN);
    expect($row->purpose)->toBe(SiteMedia::PURPOSE_LOGO_FULL);
    expect($row->path)->toContain('original');
});

it('replaces the prior logo on re-upload (singleton per variant)', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $first = $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload(), 'full');
    $second = $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload(), 'full');

    $active = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
        ->whereNull('deleted_at')
        ->get();

    expect($active)->toHaveCount(1);
    expect($active->first()->id)->toBe($second->id);

    $allTrashed = SiteMedia::withTrashed()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
        ->get();

    expect($allTrashed)->toHaveCount(2);
});

it('keeps logo_full and logo_square as separate singleton rows', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload('full.png'), 'full');
    $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload('square.png'), 'square');

    $rows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->whereNull('deleted_at')
        ->get();

    expect($rows->pluck('purpose')->sort()->values()->all())
        ->toBe([SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE]);
});

it('appends placeholders with auto-incrementing sort_order', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $a = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('a.png'));
    $b = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('b.png'));
    $c = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('c.png'));

    expect([$a->sort_order, $b->sort_order, $c->sort_order])->toBe([0, 1, 2]);
});

it('throws when adding a 6th placeholder', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    for ($i = 0; $i < 5; $i++) {
        $service->addPlaceholder($site, $site->professional_id, makeFakeUpload("p{$i}.png"));
    }

    expect(fn () => $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('p6.png')))
        ->toThrow(\App\Services\Media\PlaceholderLimitExceededException::class);
});

it('soft-deletes a placeholder and repacks remaining sort_order with no gaps', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $a = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('a.png'));
    $b = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('b.png'));
    $c = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('c.png'));

    $service->deletePlaceholder($site, $b->id);

    $remaining = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
        ->whereNull('deleted_at')
        ->orderBy('sort_order')
        ->get();

    expect($remaining->pluck('id')->all())->toBe([$a->id, $c->id]);
    expect($remaining->pluck('sort_order')->all())->toBe([0, 1]);
});

it('reorders placeholders by the supplied id list', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $a = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('a.png'));
    $b = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('b.png'));
    $c = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('c.png'));

    $service->reorderPlaceholders($site, [$c->id, $a->id, $b->id]);

    $rows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
        ->whereNull('deleted_at')
        ->orderBy('sort_order')
        ->get();

    expect($rows->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('soft-deletes a logo by variant', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload('full.png'), 'full');

    $service->deleteLogo($site, 'full');

    $active = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
        ->whereNull('deleted_at')
        ->count();

    expect($active)->toBe(0);

    // Row is soft-deleted, not hard-deleted.
    $trashed = SiteMedia::withTrashed()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
        ->count();

    expect($trashed)->toBe(1);
});

it('returns 404 when deleting a logo that does not exist', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $service->deleteLogo($site, 'full');
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Logo not found.');

it('deletes logo_square without affecting logo_full', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload('full.png'), 'full');
    $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload('square.png'), 'square');

    $service->deleteLogo($site, 'square');

    $remaining = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->whereNull('deleted_at')
        ->get();

    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->purpose)->toBe(SiteMedia::PURPOSE_LOGO_FULL);
});

it('lists design media in the shape readers expect', function () {
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload('full.png'), 'full');
    $service->upsertLogoFromUploadedFile($site, $site->professional_id, makeFakeUpload('square.png'), 'square');
    $a = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('a.png'));
    $b = $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('b.png'));

    // Manually mark them ready since we faked the variant pipeline.
    SiteMedia::query()->where('site_id', $site->id)->update([
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
    ]);

    // Seed media_variants so listDesignMedia returns non-null URLs (the fake
    // ImageVariantService doesn't run the real variant pipeline).
    $now = now()->toDateTimeString();
    foreach (SiteMedia::query()->where('site_id', $site->id)->get() as $row) {
        DB::connection('pgsql')->table('site.media_variants')->insert([
            'id' => (string) Str::uuid(),
            'media_id' => $row->id,
            'variant_key' => 'optimized',
            'artifact_type' => 'webp',
            'disk' => 'media',
            'path' => "images/{$row->id}/optimized.webp",
            'mime' => 'image/webp',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $payload = $service->listDesignMedia($site->id);

    expect($payload)->toHaveKeys(['logo', 'placeholders']);
    expect($payload['logo'])->toHaveKeys(['full_url', 'square_url']);
    expect($payload['placeholders'])->toHaveCount(2);
    expect($payload['placeholders'][0])->toHaveKeys(['id', 'alt_text', 'url', 'sort_order']);
});

it('still returns ready placeholders whose optimized URL did not resolve', function () {
    // Regression: previously listDesignMedia skipped ready rows with a null
    // URL while addPlaceholder counted them toward PLACEHOLDER_MAX. That
    // mismatch let the dashboard hit "Placeholder image limit reached" while
    // displaying zero cards — and with no UI affordance to clear the orphans.
    // The list must surface every non-failed row so count == visible.
    $site = makeBrandSite();
    $service = makeServiceWithFakeImageVariant();

    $service->addPlaceholder($site, $site->professional_id, makeFakeUpload('orphan.png'));

    SiteMedia::query()->where('site_id', $site->id)->update([
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
    ]);
    // Intentionally do NOT seed media_variants — the row is ready but its
    // optimized URL won't resolve, mirroring rows whose disk reference no
    // longer exists in filesystems config.

    $payload = $service->listDesignMedia($site->id);

    expect($payload['placeholders'])->toHaveCount(1);
    expect($payload['placeholders'][0]['url'])->toBe('');
    expect($payload['placeholders'][0]['processing_state'])->toBe(SiteMedia::PROCESSING_STATE_READY);
});
