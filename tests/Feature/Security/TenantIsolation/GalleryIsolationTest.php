<?php

use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalGalleryController;
use App\Http\Requests\Api\Professional\ImageGallery\UpdateGalleryImageRequest;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupMediaTables();
});

it('gallery destroy refuses an image belonging to another professionals site', function () {
    [$a, $b] = createTwoTenants('affiliate');

    $imageId = (string) Str::uuid();
    DB::table('site.site_media')->insert([
        'id' => $imageId,
        'site_id' => $a->site->id,
        'professional_id' => $a->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => 'image',
        'path' => "images/{$a->id}/{$imageId}/original.jpg",
        'processing_state' => 'ready',
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $image = SiteMedia::query()->findOrFail($imageId);
    $req = tenantRequestAs($b, [], 'DELETE');

    // abort_unless($image->site_id === $site->id, 404) rejects before any
    // media service / cache invalidation runs — no mocks required.
    expect(fn () => app(ProfessionalGalleryController::class)->destroy($req, $image))
        ->toThrow(HttpException::class);

    // Row must still exist (not soft-deleted).
    $row = DB::table('site.site_media')->where('id', $imageId)->first();
    expect($row)->not->toBeNull();
    expect($row->deleted_at)->toBeNull();
    expect($row->site_id)->toBe($a->site->id);
});

it('gallery update refuses an image belonging to another professionals site', function () {
    [$a, $b] = createTwoTenants('affiliate');

    $imageId = (string) Str::uuid();
    DB::table('site.site_media')->insert([
        'id' => $imageId,
        'site_id' => $a->site->id,
        'professional_id' => $a->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'media_type' => 'image',
        'path' => "images/{$a->id}/{$imageId}/original.jpg",
        'alt_text' => 'A alt',
        'caption' => 'A caption',
        'processing_state' => 'ready',
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $image = SiteMedia::query()->findOrFail($imageId);

    // Build a typed FormRequest so controller signature is satisfied.
    $base = Request::create("/api/gallery/{$imageId}", 'PATCH', ['alt_text' => 'Hacked', 'caption' => 'Hacked']);
    $base->attributes->set('professional', $b);
    $req = UpdateGalleryImageRequest::createFrom($base);
    $req->setContainer(app())->setRedirector(app('redirect'));
    $req->validateResolved();
    $req->attributes->set('professional', $b);

    expect(fn () => app(ProfessionalGalleryController::class)->update($req, $image))
        ->toThrow(HttpException::class);

    // Alt text / caption must be unchanged.
    $row = DB::table('site.site_media')->where('id', $imageId)->first();
    expect($row->alt_text)->toBe('A alt');
    expect($row->caption)->toBe('A caption');
});
