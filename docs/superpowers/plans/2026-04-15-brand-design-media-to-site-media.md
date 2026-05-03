# Brand Design Media → site_media Source-of-Truth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move brand logos and placeholder sitepage images out of `site.sites.settings.design.{logo, media.placeholder_sitepage_images}` JSONB and into `site.site_media` as the single source of truth, fix the placeholder soft-delete singleton bug, and have Shopify sync write to `site_media` too.

**Architecture:** Add a `purpose` discriminator column (`logo_full`, `logo_square`, `placeholder`) to `site_media` for clean slot identification. Factor a `BrandDesignMediaService` that every writer/reader funnels through (upload controller, Shopify sync job, Hydrogen brand-design endpoint, brand dashboard endpoint, public-site cache). All three image slots share the existing `ProcessImageVariantsJob` pipeline. Backfill any existing JSONB-only data into `site_media`, then strip the JSONB keys.

**Tech Stack:** Laravel 12, Pest 4, PostgreSQL (Supabase) with `site_media` already pool-aware, GD-based `ImageVariantService`, queued `ProcessImageVariantsJob`.

**Pre-beta context:** No customers, single dev environment, Laravel Cloud auto-deploys from `development-v2`. The user has explicitly said to skip phased rollout — ship as one coherent change. Frontend changes (dashboard + Hydrogen) happen in a separate session, but the backend is free to break legacy frontend shapes — this plan optimizes the response shapes rather than preserving them. Required frontend changes are summarized at the bottom of this document.

---

## File Map

### Create
- `app/Services/Media/BrandDesignMediaService.php` — single writer/reader for brand design media. Methods: `upsertLogoFromUploadedFile`, `upsertLogoFromBytes`, `addPlaceholder`, `deletePlaceholder`, `reorderPlaceholders`, `listDesignMedia`. Both upload controller and Shopify sync go through this.
- `app/Http/Requests/Api/Professional/Uploads/ReorderBrandPlaceholdersRequest.php` — validates `{ ordered_ids: [uuid, ...] }`.
- `supabase/migrations/20260415120000_add_purpose_to_site_media.sql` — adds `purpose` column, drops the `alt_text='logo'` singleton index, adds `purpose='logo_full'`/`purpose='logo_square'` singleton indexes and `purpose='placeholder'` per-site sort_order index.
- `supabase/migrations/20260415120100_backfill_brand_design_media.sql` — for each site, ensures site_media rows exist for any logo/placeholder still in JSONB, then strips the JSONB keys.
- `tests/Unit/BrandDesignMediaServiceTest.php` — unit coverage for the service.
- `tests/Feature/Brand/BrandPlaceholderEndpointsTest.php` — endpoint coverage for upload/list/reorder/delete/limits.

### Modify
- `app/Models/Core/Site/SiteMedia.php` — add `PURPOSE_LOGO_FULL` / `PURPOSE_LOGO_SQUARE` / `PURPOSE_PLACEHOLDER` constants; add `purpose` to `$fillable`.
- `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php` — refactor `storeBrandDesignImage` to delegate to `BrandDesignMediaService`; add `listBrandPlaceholders`, `destroyBrandPlaceholder`, `reorderBrandPlaceholders`. Replace the `alt_text` overload with `purpose`.
- `app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php` — `buildDesignPayload` reads logo + placeholders from `BrandDesignMediaService` and **returns a simplified shape**: `placeholders: [{url, alt_text}]` replaces `placeholder_sitepage_images: [{url, name, path}]`.
- `app/Http/Controllers/Api/Professional/Store/BrandDesignController.php` — `show` reads logo + placeholders from `BrandDesignMediaService` and **adds a `placeholders` field** to the response so the dashboard reads everything in one call.
- `app/Http/Resources/BrandDesignResource.php` — pass through the new `placeholders` field.
- `app/Http/Requests/Api/Professional/Uploads/UploadBrandLogoRequest.php` — accept optional `variant=full|square` (default `full`) so square logos can be uploaded via the same endpoint.
- `app/Services/Cache/SiteCacheService.php` — `applyBrandImageFallbacks` queries the service.
- `app/Jobs/Shopify/SyncShopifyBrandDesignJob.php` — `mirrorLogo` returns bytes (not URL) and the job calls `BrandDesignMediaService::upsertLogoFromBytes`. Stops writing `$design['logo']` to JSONB.
- `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` — drop `placeholder_sitepage_images*` rules; mark `settings.design.logo`, `settings.design.logo.full_url`, `settings.design.logo.square_url`, `settings.design.media.placeholder_sitepage_images` as `prohibited`.
- `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php` — same drops + `prohibited` rules.
- `app/Actions/Site/UpdateSiteAction.php` — drop `'design.media.placeholder_sitepage_images'` from `$listKeys`.
- `routes/api/professional.php` — add `GET /uploads/brand-placeholder-images`, `DELETE /uploads/brand-placeholder-images/{media}`, `POST /uploads/brand-placeholder-images/reorder`.
- `tests/Pest.php` — add `purpose` column to `setupMediaTables()`.
- `tests/Feature/Brand/BrandDesignUploadTest.php` — update assertions for `purpose`; add multi-placeholder scenario.
- `tests/Feature/Shopify/SyncShopifyBrandDesignJobTest.php` — assert `site_media` rows instead of `settings.design.logo`.

---

## Architectural Decisions Locked In

1. **`purpose` column, not `alt_text` overload.** `alt_text` should mean accessibility text for screen readers, not slot identification. Adding a discriminator column also frees up the existing singleton index (`site_media_design_logo_uq`) to be replaced with two purpose-scoped indexes (one for logo_full, one for logo_square) so the Shopify-imported full and square logos can coexist.
2. **Logo full + square are two rows, not one.** Today there is a single `alt_text='logo'` row, which doesn't even match the storage shape (the JSONB has `full_url` AND `square_url`). The new model creates one row per slot.
3. **Placeholders use `sort_order`, capped at 5.** The cap matches the existing `max:5` validation rule. Insert assigns `(max sort_order for purpose='placeholder' on this site) + 1`. A new per-site partial index enforces uniqueness of `(site_id, sort_order)` for placeholder rows only.
4. **Single source of truth: site_media. No dual-write.** Per the user's explicit instruction, skip the cautious dual-write rollout phase. Reads come from site_media; the JSONB keys are stripped in the backfill migration; validators reject any future client attempt to repopulate them.
5. **`BrandDesignMediaService` is the only seam.** Both `ProfessionalUploadController` (multipart upload from dashboard) and `SyncShopifyBrandDesignJob` (HTTP-fetched bytes from Shopify CDN) call into the same service. The service handles row creation, soft-delete-prior-singleton for logos, sort_order packing for placeholders, original storage, and `ProcessImageVariantsJob` dispatch.
6. **`BrandDesignMediaService::listDesignMedia($siteId)` is the only read seam.** Returns `{ logo: { full_url, square_url }, placeholders: [{ id, alt_text, url, sort_order }] }`. Three callers consume it (`HydrogenBrandDesignController`, `BrandDesignController` dashboard `/show`, `SiteCacheService` affiliate fallback) and each projects the shape they want.
7. **Response shapes are not preserved for legacy frontend compatibility.** The user has confirmed Hydrogen and the dashboard frontend will be updated in a follow-up. Hydrogen's response renames `placeholder_sitepage_images` → `placeholders` and drops the unused `name` / `path` fields in favor of `alt_text`. Dashboard `/brand/design` gains a `placeholders` field so it doesn't need a second round-trip.
8. **Single logo upload endpoint with `variant` discriminator.** Rather than adding `POST /uploads/brand-logo-square`, extend the existing `POST /uploads/brand-logo` to accept an optional `variant=full|square` field (default `full`). Keeps the route surface small and lets one request class handle both slots.

---

## Task 1: Schema migration — add `purpose` column

**Files:**
- Create: `supabase/migrations/20260415120000_add_purpose_to_site_media.sql`
- Modify: `tests/Pest.php` (`setupMediaTables()`)
- Modify: `app/Models/Core/Site/SiteMedia.php`

This task lays the schema and model foundation. No application code consumes `purpose` yet — that comes in Task 3.

- [ ] **Step 1: Create the migration file**

Create `supabase/migrations/20260415120000_add_purpose_to_site_media.sql` with the following content. The migration is wrapped in a transaction; the partial indexes are recreated rather than CONCURRENTLY because pre-beta has no real traffic.

```sql
-- Add a `purpose` discriminator column to site.site_media for brand design
-- assets, replacing the alt_text='logo'|'placeholder' string match used by the
-- previous design-pool singleton index.
--
-- Why: alt_text is supposed to hold accessibility text. Overloading it for slot
-- identification was always fragile, and it can't distinguish the full logo
-- from the square logo (Shopify gives us both — the old code only stored one).
-- A dedicated column lets us:
--   1. Hold logo_full + logo_square as two coexisting singleton rows per site.
--   2. Order placeholders by sort_order within their own per-site namespace.
--   3. Clean up alt_text for actual a11y text in a follow-up.
--
-- This migration is non-destructive: it backfills purpose from alt_text for
-- any existing design-pool rows and keeps alt_text intact (we'll repurpose it
-- to a11y text later, but not in this migration).

BEGIN;

ALTER TABLE site.site_media
    ADD COLUMN IF NOT EXISTS purpose text;

-- Backfill: any existing design-pool rows used alt_text='logo' or 'placeholder'.
-- Map them to the new purpose values. logo → logo_full (the old code only
-- stored one logo per site, conceptually the "full" version).
UPDATE site.site_media
SET purpose = 'logo_full'
WHERE pool = 'design'
  AND alt_text = 'logo'
  AND purpose IS NULL;

UPDATE site.site_media
SET purpose = 'placeholder'
WHERE pool = 'design'
  AND alt_text = 'placeholder'
  AND purpose IS NULL;

-- Drop the old alt_text-scoped singleton index and replace with two
-- purpose-scoped indexes so logo_full and logo_square can coexist.
DROP INDEX IF EXISTS site.site_media_design_logo_uq;

CREATE UNIQUE INDEX site_media_design_logo_full_uq
    ON site.site_media (site_id)
    WHERE pool = 'design'
      AND purpose = 'logo_full'
      AND deleted_at IS NULL;

CREATE UNIQUE INDEX site_media_design_logo_square_uq
    ON site.site_media (site_id)
    WHERE pool = 'design'
      AND purpose = 'logo_square'
      AND deleted_at IS NULL;

-- Placeholders need stable per-site sort_order with no gaps. Scope uniqueness
-- to (site_id, sort_order) for placeholder rows only — gallery/content pools
-- already have their own per-pool index from 20260414100000.
CREATE UNIQUE INDEX site_media_design_placeholder_sort_uq
    ON site.site_media (site_id, sort_order)
    WHERE pool = 'design'
      AND purpose = 'placeholder'
      AND deleted_at IS NULL
      AND is_active = true;

COMMIT;
```

- [ ] **Step 2: Update the test schema bootstrap**

The Pest test suite uses SQLite in-memory and reconstructs the relevant tables manually. Add the `purpose` column to `setupMediaTables()` so model writes don't fail in tests.

In `tests/Pest.php`, locate the `setupMediaTables()` function (lines 166–215). Find the `site.site_media` `CREATE TABLE` statement and add the `purpose` column after `alt_text`:

Replace:

```php
        product_gid TEXT NULL,
        alt_text TEXT NULL,
        created_at TEXT NULL,
```

With:

```php
        product_gid TEXT NULL,
        alt_text TEXT NULL,
        purpose TEXT NULL,
        created_at TEXT NULL,
```

- [ ] **Step 3: Update SiteMedia model**

In `app/Models/Core/Site/SiteMedia.php`, add purpose constants and fillable entry.

After line 28 (`public const POOL_DESIGN = 'design';`), add:

```php

    // Brand-design slot discriminator inside POOL_DESIGN. Replaces the old
    // alt_text='logo'|'placeholder' string match — alt_text is now reserved
    // for accessibility text. Set to NULL for non-design rows.
    public const PURPOSE_LOGO_FULL   = 'logo_full';
    public const PURPOSE_LOGO_SQUARE = 'logo_square';
    public const PURPOSE_PLACEHOLDER = 'placeholder';
```

Then add `'purpose'` to the `$fillable` array (after `'alt_text'`):

Replace:

```php
    protected $fillable = [
        'site_id',
        'pool',
        'path',
        'alt_text',
        'sort_order',
```

With:

```php
    protected $fillable = [
        'site_id',
        'pool',
        'path',
        'alt_text',
        'purpose',
        'sort_order',
```

- [ ] **Step 4: Run the existing test suite to confirm nothing regressed**

Run: `composer test`
Expected: all tests pass. The schema column is additive; no existing code reads or writes `purpose` yet.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20260415120000_add_purpose_to_site_media.sql tests/Pest.php app/Models/Core/Site/SiteMedia.php
git commit -m "$(cat <<'EOF'
feat(media): add purpose column to site_media for brand design slots

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Build BrandDesignMediaService (TDD)

**Files:**
- Create: `app/Services/Media/BrandDesignMediaService.php`
- Test: `tests/Unit/BrandDesignMediaServiceTest.php`

This service is the only writer/reader for brand design media. Both upload controller and Shopify sync go through it. Built test-first.

The service exposes six methods:
- `upsertLogoFromUploadedFile(Site $site, string $proId, UploadedFile $file, string $variant): SiteMedia` — variant is `'full'|'square'`.
- `upsertLogoFromBytes(Site $site, string $proId, string $bytes, string $mime, string $variant): SiteMedia` — Shopify path; bytes already in memory.
- `addPlaceholder(Site $site, string $proId, UploadedFile $file): SiteMedia` — appends at `max+1`, throws if >= 5 already active.
- `deletePlaceholder(Site $site, string $mediaId): void` — soft-delete + repack remaining placeholder sort_order.
- `reorderPlaceholders(Site $site, array $orderedIds): void` — assign sort_order by array index inside a transaction.
- `listDesignMedia(string $siteId): array` — returns `['logo' => ['full_url' => …, 'square_url' => …], 'placeholders' => [['id' => …, 'name' => …, 'url' => …, 'sort_order' => …], …]]`. Used by every reader.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/BrandDesignMediaServiceTest.php`. The test uses Pest's unit suite which extends `Tests\TestCase` via the `uses(...)` helper. Set up the test schema and exercise all six methods.

```php
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
        'subdomain' => 'test-' . substr($siteId, 0, 8),
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

    $payload = $service->listDesignMedia($site->id);

    expect($payload)->toHaveKeys(['logo', 'placeholders']);
    expect($payload['logo'])->toHaveKeys(['full_url', 'square_url']);
    expect($payload['placeholders'])->toHaveCount(2);
    expect($payload['placeholders'][0])->toHaveKeys(['id', 'alt_text', 'url', 'sort_order']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/BrandDesignMediaServiceTest.php -v`
Expected: FAIL with "Class App\Services\Media\BrandDesignMediaService not found".

- [ ] **Step 3: Create the placeholder limit exception**

Create `app/Services/Media/PlaceholderLimitExceededException.php`:

```php
<?php

namespace App\Services\Media;

class PlaceholderLimitExceededException extends \DomainException
{
    public function __construct(int $max)
    {
        parent::__construct("Placeholder image limit reached (max {$max}).");
    }
}
```

- [ ] **Step 4: Create the BrandDesignMediaService**

Create `app/Services/Media/BrandDesignMediaService.php`:

```php
<?php

namespace App\Services\Media;

use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// V2: Single seam for brand design media — logo (full + square) and
// placeholders. Both ProfessionalUploadController (multipart) and
// SyncShopifyBrandDesignJob (Shopify-fetched bytes) call into this service so
// every brand design asset goes through the same persistence + variant pipeline.
class BrandDesignMediaService
{
    public const PLACEHOLDER_MAX = 5;

    public function __construct(
        private readonly ImageVariantService $images,
    ) {}

    /**
     * Upload a logo from a multipart UploadedFile. Variant is 'full' or 'square'.
     * Soft-deletes any prior active row with the same purpose so the singleton
     * index holds.
     */
    public function upsertLogoFromUploadedFile(Site $site, string $proId, UploadedFile $file, string $variant): SiteMedia
    {
        $purpose = $this->purposeForLogoVariant($variant);

        $media = $this->createDesignRow($site, $purpose, $file->getMimeType(), $file->getSize(), 0);

        $basePath = "images/{$proId}/{$media->id}";

        try {
            $originalPath = $this->images->storeOriginal($file, $basePath);
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: failed to store logo original.', [
                'site_id' => $site->id,
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);
            $media->delete();
            throw $e;
        }

        $media->update(['path' => $originalPath]);
        $this->dispatchVariantJob($media->id, $originalPath, $basePath);
        $this->invalidateSiteCache($site);

        return $media->refresh();
    }

    /**
     * Upload a logo from raw bytes already in memory (Shopify CDN download path).
     * Same singleton-replace semantics as upsertLogoFromUploadedFile.
     */
    public function upsertLogoFromBytes(Site $site, string $proId, string $bytes, string $mime, string $variant): SiteMedia
    {
        $purpose = $this->purposeForLogoVariant($variant);

        $media = $this->createDesignRow($site, $purpose, $mime, strlen($bytes), 0);

        $basePath = "images/{$proId}/{$media->id}";
        $ext = $this->extensionFromMime($mime);
        $hash = substr(hash('sha256', $bytes), 0, 16);
        $originalPath = "{$basePath}/original_{$hash}.{$ext}";

        try {
            Storage::disk($this->images->resolvedDiskName())->put($originalPath, $bytes, 'public');
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: failed to store logo bytes.', [
                'site_id' => $site->id,
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);
            $media->delete();
            throw $e;
        }

        $media->update(['path' => $originalPath]);
        $this->dispatchVariantJob($media->id, $originalPath, $basePath);
        $this->invalidateSiteCache($site);

        return $media->refresh();
    }

    /**
     * Append a placeholder image. Throws PlaceholderLimitExceededException if
     * 5 active placeholders already exist for the site.
     */
    public function addPlaceholder(Site $site, string $proId, UploadedFile $file): SiteMedia
    {
        $media = DB::transaction(function () use ($site, $file) {
            $activeCount = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->count();

            if ($activeCount >= self::PLACEHOLDER_MAX) {
                throw new PlaceholderLimitExceededException(self::PLACEHOLDER_MAX);
            }

            $maxSort = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->max('sort_order');

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DESIGN,
                'purpose' => SiteMedia::PURPOSE_PLACEHOLDER,
                'path' => '',
                'alt_text' => $file->getClientOriginalName(),
                'sort_order' => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $file->getMimeType(),
                'original_size_bytes' => $file->getSize(),
            ]);
        });

        $basePath = "images/{$proId}/{$media->id}";

        try {
            $originalPath = $this->images->storeOriginal($file, $basePath);
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: failed to store placeholder original.', [
                'site_id' => $site->id,
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();
            throw $e;
        }

        $media->update(['path' => $originalPath]);
        $this->dispatchVariantJob($media->id, $originalPath, $basePath);
        $this->invalidateSiteCache($site);

        return $media->refresh();
    }

    /**
     * Soft-delete a placeholder and repack the remaining sort_order so the list
     * has no gaps. Throws if the media id doesn't belong to this site / isn't a
     * placeholder.
     */
    public function deletePlaceholder(Site $site, string $mediaId): void
    {
        DB::transaction(function () use ($site, $mediaId) {
            $row = SiteMedia::query()
                ->where('id', $mediaId)
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                abort(404, 'Placeholder not found.');
            }

            $row->delete();

            // Re-pack remaining placeholders to (0, 1, 2, ...) — first push to
            // a high offset to avoid colliding with the partial unique index,
            // then assign final values.
            $remaining = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();

            $offset = self::PLACEHOLDER_MAX + 1000;
            foreach ($remaining as $idx => $r) {
                SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $offset + $idx]);
            }
            foreach ($remaining as $idx => $r) {
                SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $idx]);
            }
        });

        $this->invalidateSiteCache($site);
    }

    /**
     * Replace the existing sort_order of placeholders with the supplied ordering.
     * The id list must contain exactly the active placeholder ids for this site
     * (no extras, no missing rows). Two-pass update to avoid index collisions.
     */
    public function reorderPlaceholders(Site $site, array $orderedIds): void
    {
        DB::transaction(function () use ($site, $orderedIds) {
            $existing = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $existingSet = array_flip($existing);
            $orderedSet = array_flip($orderedIds);

            if (count($orderedIds) !== count($existing) || array_diff_key($existingSet, $orderedSet) !== []) {
                abort(422, 'Reorder ids must match active placeholders exactly.');
            }

            $offset = self::PLACEHOLDER_MAX + 1000;
            foreach ($orderedIds as $idx => $id) {
                SiteMedia::query()->where('id', $id)->update(['sort_order' => $offset + $idx]);
            }
            foreach ($orderedIds as $idx => $id) {
                SiteMedia::query()->where('id', $id)->update(['sort_order' => $idx]);
            }
        });

        $this->invalidateSiteCache($site);
    }

    /**
     * Resolve all brand design media for a site into the shape that every reader
     * (HydrogenBrandDesignController, BrandDesignController, SiteCacheService)
     * consumes. Only ready rows are returned — pending/failed rows are skipped.
     *
     * @return array{
     *     logo: array{full_url: ?string, square_url: ?string},
     *     placeholders: array<int, array{id: string, alt_text: ?string, url: string, sort_order: int}>
     * }
     */
    public function listDesignMedia(string $siteId): array
    {
        $rows = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->get();

        $logo = ['full_url' => null, 'square_url' => null];
        $placeholders = [];

        foreach ($rows as $row) {
            $url = $row->variantUrls()['optimized'] ?? null;
            if ($url === null) {
                continue;
            }

            if ($row->purpose === SiteMedia::PURPOSE_LOGO_FULL) {
                $logo['full_url'] = $url;
            } elseif ($row->purpose === SiteMedia::PURPOSE_LOGO_SQUARE) {
                $logo['square_url'] = $url;
            } elseif ($row->purpose === SiteMedia::PURPOSE_PLACEHOLDER) {
                $placeholders[] = [
                    'id' => $row->id,
                    'alt_text' => $row->alt_text,
                    'url' => $url,
                    'sort_order' => (int) $row->sort_order,
                ];
            }
        }

        return ['logo' => $logo, 'placeholders' => $placeholders];
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers                                                  */
    /* ------------------------------------------------------------------ */

    private function purposeForLogoVariant(string $variant): string
    {
        return match ($variant) {
            'full' => SiteMedia::PURPOSE_LOGO_FULL,
            'square' => SiteMedia::PURPOSE_LOGO_SQUARE,
            default => throw new \InvalidArgumentException("Unknown logo variant: {$variant}"),
        };
    }

    private function createDesignRow(Site $site, string $purpose, ?string $mime, ?int $size, int $sortOrder): SiteMedia
    {
        return DB::transaction(function () use ($site, $purpose, $mime, $size, $sortOrder) {
            // Singleton-replace: soft-delete any prior active row with this purpose.
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', $purpose)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (SiteMedia $row) => $row->delete());

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DESIGN,
                'purpose' => $purpose,
                'path' => '',
                'sort_order' => $sortOrder,
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $mime,
                'original_size_bytes' => $size,
            ]);
        });
    }

    private function dispatchVariantJob(string $imageId, string $originalPath, string $basePath): void
    {
        $queueDefault = (string) config('queue.default', 'sync');
        $processInline = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueDefault === 'sync';

        if ($processInline) {
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $e) {
                Log::error('BrandDesignMediaService: inline variant processing failed.', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        try {
            ProcessImageVariantsJob::dispatch(
                originalPath: $originalPath,
                imageId: $imageId,
                basePath: $basePath,
            );
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: queue dispatch failed; falling back to sync.', [
                'image_id' => $imageId,
                'error' => $e->getMessage(),
            ]);
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $sync) {
                Log::error('BrandDesignMediaService: sync fallback also failed.', [
                    'image_id' => $imageId,
                    'error' => $sync->getMessage(),
                ]);
            }
        }
    }

    private function invalidateSiteCache(Site $site): void
    {
        try {
            app(SiteCacheService::class)->invalidateSite($site);
        } catch (Throwable $e) {
            Log::warning('BrandDesignMediaService: cache invalidation failed.', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extensionFromMime(string $mime): string
    {
        return match (strtolower(trim(explode(';', $mime)[0] ?? ''))) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}
```

- [ ] **Step 5: Run the unit tests**

Run: `vendor/bin/pest tests/Unit/BrandDesignMediaServiceTest.php -v`
Expected: PASS for all 8 cases.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Media/BrandDesignMediaService.php app/Services/Media/PlaceholderLimitExceededException.php tests/Unit/BrandDesignMediaServiceTest.php
git commit -m "$(cat <<'EOF'
feat(media): add BrandDesignMediaService for unified logo + placeholder writes

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Refactor ProfessionalUploadController to use the service

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`
- Modify: `app/Http/Requests/Api/Professional/Uploads/UploadBrandLogoRequest.php`
- Modify: `tests/Feature/Brand/BrandDesignUploadTest.php`

Replace `storeBrandDesignImage`'s inline transaction + scratchpad pattern with a thin call into `BrandDesignMediaService`. Extend `UploadBrandLogoRequest` with an optional `variant=full|square` field so the same endpoint handles both logo slots. The placeholder upload endpoint stays the same shape (just delegates to `BrandDesignMediaService::addPlaceholder`).

- [ ] **Step 1: Update the existing logo upload test for the new `purpose` shape**

In `tests/Feature/Brand/BrandDesignUploadTest.php`, update three assertions to use `purpose` instead of `alt_text`:

Replace (around lines 140-143):

```php
    expect($rows)->toHaveCount(1);
    expect($rows->first()->pool)->toBe(SiteMedia::POOL_DESIGN);
    expect($rows->first()->alt_text)->toBe('logo');
});
```

With:

```php
    expect($rows)->toHaveCount(1);
    expect($rows->first()->pool)->toBe(SiteMedia::POOL_DESIGN);
    expect($rows->first()->purpose)->toBe(SiteMedia::PURPOSE_LOGO_FULL);
});
```

Replace (around lines 156-175 — the "replaces the previous logo on re-upload" test):

```php
    // Exactly one active logo row per site — the previous one is soft-deleted.
    $activeLogos = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('alt_text', 'logo')
        ->whereNull('deleted_at')
        ->get();

    expect($activeLogos)->toHaveCount(1);

    // The first row was soft-deleted, not hard-deleted (30-day retention).
    $allLogos = SiteMedia::withTrashed()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('alt_text', 'logo')
        ->get();
```

With:

```php
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
```

Replace (around lines 188-197 — the combo logo + placeholder test):

```php
    $designRows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->whereNull('deleted_at')
        ->get();

    expect($designRows)->toHaveCount(2);
    expect($designRows->pluck('alt_text')->sort()->values()->all())
        ->toBe(['logo', 'placeholder']);
});
```

With:

```php
    $designRows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->whereNull('deleted_at')
        ->get();

    expect($designRows)->toHaveCount(2);
    expect($designRows->pluck('purpose')->sort()->values()->all())
        ->toBe([SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_PLACEHOLDER]);
});
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `vendor/bin/pest tests/Feature/Brand/BrandDesignUploadTest.php -v`
Expected: FAIL — `purpose` is null in the existing controller (it doesn't write the column yet).

- [ ] **Step 3: Add `variant` to UploadBrandLogoRequest**

In `app/Http/Requests/Api/Professional/Uploads/UploadBrandLogoRequest.php`, accept an optional `variant` field. Replace the entire file with:

```php
<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// V2: Validates brand logo upload — JPEG, PNG, or WebP image file up to 5 MB.
// Optional `variant` field selects the slot: 'full' (default) or 'square'.
class UploadBrandLogoRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:5120',
            ],
            'variant' => ['sometimes', 'string', Rule::in(['full', 'square'])],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.mimes' => 'Brand logo must be a JPEG, PNG, or WebP image.',
            'logo.max' => 'Brand logo must be smaller than 5 MB.',
            'variant.in' => 'Logo variant must be one of: full, square.',
        ];
    }
}
```

- [ ] **Step 4: Refactor the controller**

In `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`:

Add the import near the other `use` statements at the top of the file:

```php
use App\Services\Media\BrandDesignMediaService;
```

Add a constructor parameter so Laravel injects the service. Replace the existing constructor (lines 33-35):

```php
    public function __construct(
        private readonly ImageVariantService $mediaService,
    ) {}
```

With:

```php
    public function __construct(
        private readonly ImageVariantService $mediaService,
        private readonly BrandDesignMediaService $brandDesign,
    ) {}
```

Update `uploadBrandLogo` to read the `variant` field from the validated request and pass it through. Replace the existing method (lines 402-413):

```php
    public function uploadBrandLogo(UploadBrandLogoRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Brand logo uploads are only available for brand accounts.', 403);
        }

        return $this->storeBrandDesignImage($pro, $site, $request->file('logo'), 'logo');
    }
```

With:

```php
    public function uploadBrandLogo(UploadBrandLogoRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Brand logo uploads are only available for brand accounts.', 403);
        }

        $variant = $request->validated('variant') ?? 'full';
        $label = $variant === 'square' ? 'logo_square' : 'logo_full';

        return $this->storeBrandDesignImage($pro, $site, $request->file('logo'), $label);
    }
```

Replace the entire `storeBrandDesignImage` private method (lines 431-500) with a thin delegation:

```php
    private function storeBrandDesignImage(
        \App\Models\Core\Professional\Professional $pro,
        \App\Models\Core\Site\Site $site,
        \Illuminate\Http\UploadedFile $file,
        string $label,
    ): JsonResponse {
        // $label is one of: 'logo_full', 'logo_square', or 'placeholder'.
        // The brand-logo and brand-placeholder routes both funnel through here
        // so BrandDesignMediaService is the only writer.
        try {
            $media = match ($label) {
                'logo_full' => $this->brandDesign->upsertLogoFromUploadedFile($site, $pro->id, $file, 'full'),
                'logo_square' => $this->brandDesign->upsertLogoFromUploadedFile($site, $pro->id, $file, 'square'),
                'placeholder' => $this->brandDesign->addPlaceholder($site, $pro->id, $file),
                default => throw new \InvalidArgumentException("Unknown brand design label: {$label}"),
            };
        } catch (\App\Services\Media\PlaceholderLimitExceededException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error("Brand {$label} upload failed.", [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Failed to upload brand design asset.', 500);
        }

        $media->load('mediaVariants');
        $isReady = $media->processing_state === SiteMedia::PROCESSING_STATE_READY;
        $variants = $isReady ? $media->variantUrls() : [];
        $mediaDisk = $this->mediaService->resolvedDiskName();

        return $this->success([
            'path' => $media->path,
            'url' => $variants['optimized'] ?? Storage::disk($mediaDisk)->url($media->path),
            'name' => $file->getClientOriginalName(),
            'disk' => $mediaDisk,
            'site_id' => $site->id,
            'media_id' => $media->id,
            'media_purpose' => $media->purpose,
            'sort_order' => (int) $media->sort_order,
            'variants' => $variants,
            'processing_state' => $media->processing_state,
        ], 201);
    }
```

- [ ] **Step 5: Update the test helper that constructs the controller**

In `tests/Feature/Brand/BrandDesignUploadTest.php` `makeMockedUploadController()` (lines 115-125), inject the real service (it'll use the same fake media disk and Bus facade as the rest of the test):

Replace:

```php
function makeMockedUploadController(): ProfessionalUploadController
{
    $mediaService = Mockery::mock(ImageVariantService::class);
    // storeOriginal is invoked once per upload — return a deterministic path
    // derived from the basePath the controller computes.
    $mediaService->shouldReceive('storeOriginal')
        ->andReturnUsing(fn ($file, $basePath) => "{$basePath}/original.png");
    $mediaService->shouldReceive('resolvedDiskName')->andReturn('media');

    return new ProfessionalUploadController($mediaService);
}
```

With:

```php
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
```

- [ ] **Step 6: Run the test to confirm it now passes**

Run: `vendor/bin/pest tests/Feature/Brand/BrandDesignUploadTest.php -v`
Expected: all existing cases PASS with the new `purpose` shape.

- [ ] **Step 7: Run the full suite to catch unintended regressions**

Run: `composer test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php app/Http/Requests/Api/Professional/Uploads/UploadBrandLogoRequest.php tests/Feature/Brand/BrandDesignUploadTest.php
git commit -m "$(cat <<'EOF'
refactor(uploads): delegate brand design uploads to BrandDesignMediaService

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Add placeholder list / delete / reorder endpoints

**Files:**
- Create: `app/Http/Requests/Api/Professional/Uploads/ReorderBrandPlaceholdersRequest.php`
- Modify: `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`
- Modify: `routes/api/professional.php`
- Create: `tests/Feature/Brand/BrandPlaceholderEndpointsTest.php`

The placeholder list now lives in `site_media`, so the dashboard needs endpoints to fetch, reorder, and delete individual entries. The upload endpoint stays as-is (Task 3 already wired it). All three new endpoints live on `ProfessionalUploadController` next to the existing brand methods.

- [ ] **Step 1: Write the failing endpoint tests**

Create `tests/Feature/Brand/BrandPlaceholderEndpointsTest.php`:

```php
<?php

use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\BrandDesignMediaService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();

    Storage::fake('media');
    Bus::fake();

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->andReturnNull()->byDefault();
    app()->instance(SiteCacheService::class, $cache);
});

function makeBrandWithSiteForPlaceholders(string $handle = 'placeholderbrand'): array
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

function makeRealController(): ProfessionalUploadController
{
    $mediaService = Mockery::mock(ImageVariantService::class);
    $mediaService->shouldReceive('storeOriginal')
        ->andReturnUsing(fn ($file, $basePath) => "{$basePath}/original.png");
    $mediaService->shouldReceive('resolvedDiskName')->andReturn('media');

    $brandDesign = new BrandDesignMediaService($mediaService);

    return new ProfessionalUploadController($mediaService, $brandDesign);
}

function makePlaceholderUpload(Professional $brand): \App\Http\Requests\Api\Professional\Uploads\UploadBrandPlaceholderImageRequest
{
    $image = UploadedFile::fake()->image('p.png', 256, 256);
    $base = Request::create('/api/uploads/brand-placeholder-image', 'POST', [], [], ['image' => $image]);
    /** @var \App\Http\Requests\Api\Professional\Uploads\UploadBrandPlaceholderImageRequest $req */
    $req = \App\Http\Requests\Api\Professional\Uploads\UploadBrandPlaceholderImageRequest::createFromBase($base);
    $req->attributes->set('professional', $brand);

    $validator = Mockery::mock(\Illuminate\Contracts\Validation\Validator::class);
    $validator->shouldReceive('validated')->andReturn([]);
    $req->setValidator($validator);

    return $req;
}

it('lists active placeholders sorted by sort_order', function () {
    [$brand, $site] = makeBrandWithSiteForPlaceholders('listbrand');
    $controller = makeRealController();

    $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand));
    $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand));
    $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand));

    // Manually mark them ready (the fake variant service skips the pipeline).
    SiteMedia::query()->where('site_id', $site->id)->update([
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
    ]);

    $request = Request::create('/api/uploads/brand-placeholder-images', 'GET');
    $request->attributes->set('professional', $brand);

    $response = $controller->listBrandPlaceholders($request);
    $payload = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($payload['data']['placeholders'])->toHaveCount(3);
    expect($payload['data']['placeholders'][0]['sort_order'])->toBe(0);
    expect($payload['data']['placeholders'][1]['sort_order'])->toBe(1);
    expect($payload['data']['placeholders'][2]['sort_order'])->toBe(2);
});

it('rejects a 6th placeholder with 422', function () {
    [$brand, $site] = makeBrandWithSiteForPlaceholders('limitbrand');
    $controller = makeRealController();

    for ($i = 0; $i < 5; $i++) {
        $resp = $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand));
        expect($resp->getStatusCode())->toBe(201);
    }

    $sixth = $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand));
    expect($sixth->getStatusCode())->toBe(422);

    $active = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
        ->whereNull('deleted_at')
        ->count();
    expect($active)->toBe(5);
});

it('deletes a placeholder and repacks sort_order', function () {
    [$brand, $site] = makeBrandWithSiteForPlaceholders('deletebrand');
    $controller = makeRealController();

    $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand));
    $second = json_decode(
        $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand))->getContent(),
        true
    )['data']['media_id'];
    $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand));

    $request = Request::create("/api/uploads/brand-placeholder-images/{$second}", 'DELETE');
    $request->attributes->set('professional', $brand);

    $response = $controller->destroyBrandPlaceholder($request, $second);
    expect($response->getStatusCode())->toBe(200);

    $rows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
        ->whereNull('deleted_at')
        ->orderBy('sort_order')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows->pluck('sort_order')->all())->toBe([0, 1]);
});

it('reorders placeholders by the supplied id list', function () {
    [$brand, $site] = makeBrandWithSiteForPlaceholders('reorderbrand');
    $controller = makeRealController();

    $a = json_decode($controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand))->getContent(), true)['data']['media_id'];
    $b = json_decode($controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand))->getContent(), true)['data']['media_id'];
    $c = json_decode($controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brand))->getContent(), true)['data']['media_id'];

    $request = Request::create('/api/uploads/brand-placeholder-images/reorder', 'POST', [
        'ordered_ids' => [$c, $a, $b],
    ]);
    $request->attributes->set('professional', $brand);

    /** @var \App\Http\Requests\Api\Professional\Uploads\ReorderBrandPlaceholdersRequest $req */
    $req = \App\Http\Requests\Api\Professional\Uploads\ReorderBrandPlaceholdersRequest::createFromBase($request);
    $req->attributes->set('professional', $brand);

    $validator = Mockery::mock(\Illuminate\Contracts\Validation\Validator::class);
    $validator->shouldReceive('validated')->andReturn(['ordered_ids' => [$c, $a, $b]]);
    $req->setValidator($validator);

    $response = $controller->reorderBrandPlaceholders($req);
    expect($response->getStatusCode())->toBe(200);

    $rows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
        ->whereNull('deleted_at')
        ->orderBy('sort_order')
        ->get();

    expect($rows->pluck('id')->all())->toBe([$c, $a, $b]);
});

it('refuses delete for placeholders belonging to a different site', function () {
    [$brandA] = makeBrandWithSiteForPlaceholders('brandalpha');
    [$brandB, $siteB] = makeBrandWithSiteForPlaceholders('brandbeta');

    $controller = makeRealController();

    $bId = json_decode(
        $controller->uploadBrandPlaceholderImage(makePlaceholderUpload($brandB))->getContent(),
        true
    )['data']['media_id'];

    $request = Request::create("/api/uploads/brand-placeholder-images/{$bId}", 'DELETE');
    $request->attributes->set('professional', $brandA);

    expect(fn () => $controller->destroyBrandPlaceholder($request, $bId))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Brand/BrandPlaceholderEndpointsTest.php -v`
Expected: FAIL — the controller methods don't exist yet.

- [ ] **Step 3: Create the reorder request class**

Create `app/Http/Requests/Api/Professional/Uploads/ReorderBrandPlaceholdersRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;

// V2: Validates a brand placeholder reorder payload — list of placeholder
// media ids in the desired order. The controller verifies they all belong to
// the brand's site before applying.
class ReorderBrandPlaceholdersRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ordered_ids' => ['required', 'array', 'min:1', 'max:5'],
            'ordered_ids.*' => ['required', 'string', 'uuid'],
        ];
    }
}
```

- [ ] **Step 4: Add the three controller methods**

In `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`, add these three public methods immediately after the existing `uploadBrandPlaceholderImage` method (around line 429). Also add the import for the new request class near the other request imports.

Add this import near the top of the file:

```php
use App\Http\Requests\Api\Professional\Uploads\ReorderBrandPlaceholdersRequest;
```

Add these three methods after `uploadBrandPlaceholderImage`:

```php
    /**
     * GET /api/uploads/brand-placeholder-images
     *
     * Returns the active placeholder list for the brand's site, ordered by
     * sort_order. Each item: { id, name, url, sort_order }.
     */
    public function listBrandPlaceholders(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Placeholder image listing is only available for brand accounts.', 403);
        }

        $payload = $this->brandDesign->listDesignMedia($site->id);

        return $this->success(['placeholders' => $payload['placeholders']]);
    }

    /**
     * DELETE /api/uploads/brand-placeholder-images/{media}
     *
     * Soft-deletes a placeholder and repacks the remaining sort_order so the
     * list has no gaps.
     */
    public function destroyBrandPlaceholder(Request $request, string $media): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Placeholder management is only available for brand accounts.', 403);
        }

        $this->brandDesign->deletePlaceholder($site, $media);

        return $this->success(['deleted' => true]);
    }

    /**
     * POST /api/uploads/brand-placeholder-images/reorder
     *
     * Body: { ordered_ids: [uuid, uuid, ...] }. The list must contain every
     * active placeholder id for the site — extras or missing rows return 422.
     */
    public function reorderBrandPlaceholders(ReorderBrandPlaceholdersRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Placeholder management is only available for brand accounts.', 403);
        }

        $orderedIds = $request->validated('ordered_ids') ?? [];
        $this->brandDesign->reorderPlaceholders($site, $orderedIds);

        return $this->success(['reordered' => true]);
    }
```

- [ ] **Step 5: Wire up the routes**

In `routes/api/professional.php`, locate the existing brand upload routes (around line 165-166) and add the three new routes immediately after them.

Replace:

```php
        Route::post('/uploads', [ProfessionalUploadController::class, 'upload']);
        Route::post('/uploads/brand-logo', [ProfessionalUploadController::class, 'uploadBrandLogo']);
        Route::post('/uploads/brand-placeholder-image', [ProfessionalUploadController::class, 'uploadBrandPlaceholderImage']);
```

With:

```php
        Route::post('/uploads', [ProfessionalUploadController::class, 'upload']);
        Route::post('/uploads/brand-logo', [ProfessionalUploadController::class, 'uploadBrandLogo']);
        Route::post('/uploads/brand-placeholder-image', [ProfessionalUploadController::class, 'uploadBrandPlaceholderImage']);
        Route::get('/uploads/brand-placeholder-images', [ProfessionalUploadController::class, 'listBrandPlaceholders']);
        Route::post('/uploads/brand-placeholder-images/reorder', [ProfessionalUploadController::class, 'reorderBrandPlaceholders']);
        Route::delete('/uploads/brand-placeholder-images/{media}', [ProfessionalUploadController::class, 'destroyBrandPlaceholder'])
            ->whereUuid('media');
```

- [ ] **Step 6: Run the endpoint tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Brand/BrandPlaceholderEndpointsTest.php -v`
Expected: all 5 cases PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php app/Http/Requests/Api/Professional/Uploads/ReorderBrandPlaceholdersRequest.php routes/api/professional.php tests/Feature/Brand/BrandPlaceholderEndpointsTest.php
git commit -m "$(cat <<'EOF'
feat(uploads): add list/delete/reorder endpoints for brand placeholders

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Swap reads in HydrogenBrandDesignController + simplify response shape

**Files:**
- Modify: `app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php`

The Hydrogen-facing controller is the highest-traffic reader. Today it pulls `logo` from `settings.design.logo` and placeholders from `settings.design.media.placeholder_sitepage_images`. After this task it pulls both from `BrandDesignMediaService::listDesignMedia` AND the response shape is simplified — `placeholder_sitepage_images: [{url, name, path}]` becomes `placeholders: [{url, alt_text}]`. This is a breaking change for Hydrogen's consumer (`Sidest-Hydrogen/app/lib/engines/brand-design.server.ts`); see the Frontend Changes Summary at the bottom of this plan.

- [ ] **Step 1: Update the controller**

In `app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php`:

Add the import:

```php
use App\Services\Media\BrandDesignMediaService;
```

Inject the service via the constructor. Add this method to the class (above `show`):

```php
    public function __construct(
        private readonly BrandDesignMediaService $brandDesign,
    ) {}
```

Replace the `buildDesignPayload` method (lines 68-115) with a version that reads logo + placeholders from the service and returns the simplified shape:

```php
    /**
     * Build the design payload Hydrogen consumes.
     *
     * @return array{
     *     brand_professional_id: string,
     *     brand_handle: string|null,
     *     brand_name: string|null,
     *     shop_domain: string|null,
     *     colors: array{background: ?string, text: ?string, accent: ?string, border: ?string},
     *     corner_radius: ?string,
     *     border_thickness: ?string,
     *     section_spacing: ?string,
     *     logo: array{full_url: ?string, square_url: ?string},
     *     slogan: ?string,
     *     font_family: ?string,
     *     placeholders: array<int, array{url: string, alt_text: ?string}>,
     *     fallback_gallery: array<int, array{url: ?string, alt_text: ?string}>
     * }
     */
    private function buildDesignPayload(Professional $professional): array
    {
        $site = Site::where('professional_id', $professional->id)->first();
        $settings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];

        $colors = is_array($design['colors'] ?? null) ? $design['colors'] : [];

        // shop_domain still lives on provider_metadata — the one field we read
        // from there, purely so the Hydrogen layer can key on it when needed.
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];

        // Logo + placeholders live in site_media (pool=design, purpose=...).
        // The service resolves variant URLs into the canonical service shape;
        // the Hydrogen response keeps only the fields Hydrogen renders.
        $designMedia = $site
            ? $this->brandDesign->listDesignMedia((string) $site->id)
            : ['logo' => ['full_url' => null, 'square_url' => null], 'placeholders' => []];

        return [
            'brand_professional_id' => (string) $professional->id,
            'brand_handle' => $professional->handle,
            'brand_name' => $professional->display_name,
            'shop_domain' => Arr::get($metadata, 'shop_domain'),
            'colors' => [
                'background' => $colors['background'] ?? null,
                'text' => $colors['text'] ?? null,
                'accent' => $colors['accent'] ?? null,
                'border' => $colors['border'] ?? null,
            ],
            'corner_radius' => $design['corner_radius'] ?? null,
            'border_thickness' => $design['border_thickness'] ?? null,
            'section_spacing' => $design['section_spacing'] ?? null,
            'logo' => $designMedia['logo'],
            'slogan' => $design['slogan'] ?? null,
            'font_family' => is_string($design['font_family'] ?? null) && $design['font_family'] !== ''
                ? $design['font_family']
                : null,
            // Hydrogen-facing shape: just the fields Hydrogen renders. The
            // service-returned id and sort_order are intentionally omitted —
            // Hydrogen doesn't manage these rows, only displays them.
            'placeholders' => array_map(
                fn (array $item) => [
                    'url' => $item['url'],
                    'alt_text' => $item['alt_text'],
                ],
                $designMedia['placeholders']
            ),
            'fallback_gallery' => $this->getFallbackGallery($site),
        ];
    }
```

Delete the old `extractPlaceholderImages` method (lines 117-146) — it's no longer called.

- [ ] **Step 2: Run the controller tests (and update if needed)**

Run: `vendor/bin/pest tests/Feature/Brand/BrandDesignControllerTest.php -v`
Expected: PASS. If any Hydrogen-controller test asserts on the old `placeholder_sitepage_images` key or seeds `settings.design.logo` directly, update it to:
- Use `placeholders` as the response key.
- Insert `site_media` rows directly (with `purpose='logo_full'` etc.) plus a `media_variants` row with `variant_key='optimized'` and `artifact_type='webp'` so `listDesignMedia` returns a non-null URL.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php tests/Feature/Brand/BrandDesignControllerTest.php
git commit -m "$(cat <<'EOF'
refactor(hydrogen): read brand media from site_media + simplify response shape

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: BrandDesignController + Resource — read from service, add `placeholders`

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Store/BrandDesignController.php`
- Modify: `app/Http/Resources/BrandDesignResource.php`
- Modify: `tests/Feature/Brand/BrandDesignControllerTest.php`

This is the brand's own dashboard endpoint (`GET /professional/brand-design`). Today it returns `{colors, corner_radius, …, logo, slogan, font_family, shopify_connected}` and reads logo from JSONB. After this task the response also includes `placeholders` (sourced from `site_media`) so the dashboard reads the full design state in one round-trip.

- [ ] **Step 1: Update the resource to pass through `placeholders`**

In `app/Http/Resources/BrandDesignResource.php`, replace the entire file with:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Brand Design API response. Mirrors the unified shape returned by
// BrandDesignController::show — colours, three normalised enum buckets
// (corner_radius / border_thickness / section_spacing), logo URLs, slogan,
// font, the placeholder list, and shopify_connected.
//
// @response {
//   colors: { background, text, accent, border },          hex|null
//   corner_radius: 'square'|'default'|'pill',          (default 'default' applied upstream)
//   border_thickness: 'hairline'|'default'|'bold',     (default 'default' applied upstream)
//   section_spacing: 'tight'|'default'|'spacious',     (default 'default' applied upstream)
//   logo: { full_url, square_url },                        url|null
//   slogan: string|null,
//   font_family: string,                                    enum slug — always set (default applied upstream)
//   placeholders: [{ id, alt_text, url, sort_order }],
//   shopify_connected: bool
// }
class BrandDesignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $colors = is_array($this->resource['colors'] ?? null) ? $this->resource['colors'] : [];
        $logo = is_array($this->resource['logo'] ?? null) ? $this->resource['logo'] : [];
        $placeholders = is_array($this->resource['placeholders'] ?? null) ? $this->resource['placeholders'] : [];

        return [
            'colors' => [
                'background' => $colors['background'] ?? null,
                'text' => $colors['text'] ?? null,
                'accent' => $colors['accent'] ?? null,
                'border' => $colors['border'] ?? null,
            ],
            'corner_radius' => $this->resource['corner_radius'] ?? null,
            'border_thickness' => $this->resource['border_thickness'] ?? null,
            'section_spacing' => $this->resource['section_spacing'] ?? null,
            'logo' => [
                'full_url' => $logo['full_url'] ?? null,
                'square_url' => $logo['square_url'] ?? null,
            ],
            'slogan' => $this->resource['slogan'] ?? null,
            'font_family' => $this->resource['font_family'] ?? null,
            'placeholders' => array_map(
                fn (array $p) => [
                    'id' => $p['id'] ?? null,
                    'alt_text' => $p['alt_text'] ?? null,
                    'url' => $p['url'] ?? null,
                    'sort_order' => isset($p['sort_order']) ? (int) $p['sort_order'] : 0,
                ],
                $placeholders
            ),
            'shopify_connected' => (bool) ($this->resource['shopify_connected'] ?? false),
        ];
    }
}
```

- [ ] **Step 2: Update BrandDesignController**

In `app/Http/Controllers/Api/Professional/Store/BrandDesignController.php`:

Add the import near the others at the top:

```php
use App\Services\Media\BrandDesignMediaService;
```

Inject the service via constructor. Add a constructor method right after the constants (after line 31):

```php

    public function __construct(
        private readonly BrandDesignMediaService $brandDesign,
    ) {}
```

Replace lines 47-92 (`public function show`) with:

```php
    public function show(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $site = Site::where('professional_id', $pro->id)->first();
        $settings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];

        $colors = is_array($design['colors'] ?? null) ? $design['colors'] : [];

        // Logo + placeholders live in site_media (pool=design, purpose=...).
        // listDesignMedia resolves processed variant URLs and returns both in
        // one query, so the dashboard gets the full design state in one call.
        $designMedia = $site
            ? $this->brandDesign->listDesignMedia((string) $site->id)
            : ['logo' => ['full_url' => null, 'square_url' => null], 'placeholders' => []];

        return $this->success(new BrandDesignResource([
            'colors' => [
                'background' => $colors['background'] ?? null,
                'text' => $colors['text'] ?? null,
                'accent' => $colors['accent'] ?? null,
                'border' => $colors['border'] ?? null,
            ],
            // Fall back to the "middle" value for any unset bucket so the UI
            // always has a selected option — mirrors the font_family fallback.
            'corner_radius' => is_string($design['corner_radius'] ?? null) && $design['corner_radius'] !== ''
                ? $design['corner_radius']
                : self::DEFAULT_CORNER_RADIUS,
            'border_thickness' => is_string($design['border_thickness'] ?? null) && $design['border_thickness'] !== ''
                ? $design['border_thickness']
                : self::DEFAULT_BORDER_THICKNESS,
            'section_spacing' => is_string($design['section_spacing'] ?? null) && $design['section_spacing'] !== ''
                ? $design['section_spacing']
                : self::DEFAULT_SECTION_SPACING,
            'logo' => $designMedia['logo'],
            'slogan' => $design['slogan'] ?? null,
            // Fall back to the default for any brand whose row predates the
            // seed migration or who explicitly cleared their selection.
            'font_family' => is_string($design['font_family'] ?? null) && $design['font_family'] !== ''
                ? $design['font_family']
                : self::DEFAULT_FONT_FAMILY,
            'placeholders' => $designMedia['placeholders'],
            'shopify_connected' => $this->brandIntegration($pro->id) !== null,
        ]));
    }
```

- [ ] **Step 3: Update the existing BrandDesignControllerTest to seed via site_media**

The current test (`returns logo urls from the unified design shape on show`) seeds `settings.design.logo.full_url` directly into the JSONB. After the swap, the controller reads from `site_media`, so the test must seed `site_media` + `media_variants` rows instead.

In `tests/Feature/Brand/BrandDesignControllerTest.php`, replace the `it('returns logo urls from the unified design shape on show', ...)` test body (lines 43-128) with:

```php
it('returns logo urls + placeholders from site_media on show', function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();

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

    // Settings hold tokens (colors, font, enums) only — logo lives in site_media.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id'              => $siteId,
        'professional_id' => $brandId,
        'subdomain'       => 'designlogotest',
        'settings'        => json_encode([
            'design' => [
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

    // Seed two logo rows + two placeholder rows in site_media, plus matching
    // media_variants so listDesignMedia returns non-null URLs.
    $logoFullId = (string) Str::uuid();
    $logoSquareId = (string) Str::uuid();
    $placeholderAId = (string) Str::uuid();
    $placeholderBId = (string) Str::uuid();

    foreach ([
        [$logoFullId, 'logo_full', 0],
        [$logoSquareId, 'logo_square', 0],
        [$placeholderAId, 'placeholder', 0],
        [$placeholderBId, 'placeholder', 1],
    ] as [$id, $purpose, $sortOrder]) {
        DB::connection('pgsql')->table('site.site_media')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'pool' => 'design',
            'purpose' => $purpose,
            'path' => "images/{$brandId}/{$id}/original.png",
            'alt_text' => $purpose === 'placeholder' ? "{$id}.png" : null,
            'sort_order' => $sortOrder,
            'is_active' => 1,
            'media_type' => 'image',
            'processing_state' => 'ready',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('pgsql')->table('site.media_variants')->insert([
            'id' => (string) Str::uuid(),
            'media_id' => $id,
            'variant_key' => 'optimized',
            'artifact_type' => 'webp',
            'disk' => 'media',
            'path' => "images/{$brandId}/{$id}/optimized.webp",
            'mime' => 'image/webp',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    Storage::fake('media');

    $brand = \App\Models\Core\Professional\Professional::query()->findOrFail($brandId);
    $brand->setRelation('site', \App\Models\Core\Site\Site::query()->findOrFail($siteId));

    $request = Request::create('/api/brand/design', 'GET');
    $request->attributes->set('professional', $brand);

    $controller = app(BrandDesignController::class);
    $response   = $controller->show($request);
    $data       = $response->getData(true);

    // The response is wrapped in a JsonResource { data: {...} } envelope.
    $payload = $data['data'] ?? $data;

    expect($response->status())->toBe(200);
    expect($payload['logo']['full_url'])->not->toBeNull();
    expect($payload['logo']['square_url'])->not->toBeNull();
    expect($payload['colors']['background'])->toBe('#ffffff');
    expect($payload['font_family'])->toBe('helvetica_neue');
    expect($payload['placeholders'])->toHaveCount(2);
    expect($payload['placeholders'][0]['sort_order'])->toBe(0);
    expect($payload['placeholders'][1]['sort_order'])->toBe(1);
});
```

Add the `Storage` import at the top of the test file:

```php
use Illuminate\Support\Facades\Storage;
```

Note: the two existing 403 tests (`returns 403 when non-brand tries to view design` and `returns 403 when non-brand tries to resync design`) instantiate `new BrandDesignController()` with no args, which will break after the constructor injection. Update both calls to use the container:

Replace `$controller = new BrandDesignController();` (appearing twice) with:

```php
$controller = app(BrandDesignController::class);
```

- [ ] **Step 4: Run the BrandDesignController tests**

Run: `vendor/bin/pest tests/Feature/Brand/BrandDesignControllerTest.php -v`
Expected: PASS for all three cases (two 403 + the seeded site_media read).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Store/BrandDesignController.php app/Http/Resources/BrandDesignResource.php tests/Feature/Brand/BrandDesignControllerTest.php
git commit -m "$(cat <<'EOF'
refactor(brand): dashboard /design returns logo+placeholders from site_media

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Swap reads in SiteCacheService (affiliate fallback)

**Files:**
- Modify: `app/Services/Cache/SiteCacheService.php`

`applyBrandImageFallbacks` is what makes affiliate sites fall back to the brand's placeholder pool when the affiliate hasn't supplied their own gallery. It currently reads JSONB and injects items in the legacy `{url, name, path}` shape. Swap to the service and modernize the shape to `{url, alt_text}` to match the new Hydrogen response.

- [ ] **Step 1: Update the method**

In `app/Services/Cache/SiteCacheService.php`:

Replace `applyBrandImageFallbacks` (lines 173-212) with:

```php
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyBrandImageFallbacks(array $payload): array
    {
        $professional = $payload['professional'] ?? null;
        if (!is_array($professional) || ($professional['professional_type'] ?? null) === 'brand') {
            return $payload;
        }

        $brandPartner = $payload['site']['settings']['brand_partner'] ?? null;
        if (!is_array($brandPartner) || empty($brandPartner['professional_id'])) {
            return $payload;
        }

        $brandId = $brandPartner['professional_id'];
        $brandSite = Site::query()
            ->where('professional_id', $brandId)
            ->first();

        if (!$brandSite) {
            return $payload;
        }

        // Brand placeholders now live in site_media (pool=design, purpose=placeholder).
        // The service resolves variant URLs; we project them to { url, alt_text }
        // to match the new Hydrogen brand-design response shape.
        $designMedia = app(\App\Services\Media\BrandDesignMediaService::class)
            ->listDesignMedia((string) $brandSite->id);

        if (empty($designMedia['placeholders'])) {
            return $payload;
        }

        $placeholderImages = array_map(
            fn (array $p) => [
                'url' => $p['url'],
                'alt_text' => $p['alt_text'],
            ],
            $designMedia['placeholders']
        );

        $imageKeys = ['gallery', 'content_images'];

        foreach ($imageKeys as $key) {
            if (!isset($payload['site'][$key]) || !is_array($payload['site'][$key])) {
                continue;
            }

            if (empty($payload['site'][$key])) {
                $payload['site'][$key] = $placeholderImages;
            }
        }

        return $payload;
    }
```

- [ ] **Step 2: Run any tests that exercise the fallback path**

Run: `vendor/bin/pest tests/Feature/Brand --filter "fallback|placeholder|brand_partner" -v`
Expected: PASS. If a test exists that pre-seeds `settings.design.media.placeholder_sitepage_images` to verify the fallback path, update it to seed through the service or via direct site_media insert.

- [ ] **Step 3: Run the full test suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Cache/SiteCacheService.php
git commit -m "$(cat <<'EOF'
refactor(cache): read affiliate fallback placeholders from site_media

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: SyncShopifyBrandDesignJob — write logos to site_media

**Files:**
- Modify: `app/Jobs/Shopify/SyncShopifyBrandDesignJob.php`
- Modify: `tests/Feature/Shopify/SyncShopifyBrandDesignJobTest.php`

The Shopify sync job is the second writer of brand logos (the first being direct upload). Today it downloads bytes from Shopify CDN, stores them at a separate path, and writes URLs into `settings.design.logo`. After this task it stores them via `BrandDesignMediaService::upsertLogoFromBytes` and stops touching JSONB.

- [ ] **Step 1: Update the failing assertion test first**

In `tests/Feature/Shopify/SyncShopifyBrandDesignJobTest.php`, the existing test asserts on `$design['logo']['full_url']` etc. After the swap, those JSONB keys won't be set. The test must instead assert on `site_media` rows.

Add this import near the top of the test file (after the existing `use` statements):

```php
use App\Models\Core\Site\SiteMedia;
```

Add `setupMediaTables();` to the `beforeEach` so the site_media table exists. Locate the existing `beforeEach` (lines 19-51) and add the helper call right after `setupSitesTable();`:

Replace:

```php
beforeEach(function () {
    $conn = DB::connection('pgsql');
    foreach (['core', 'site'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    setupSitesTable();
```

With:

```php
beforeEach(function () {
    $conn = DB::connection('pgsql');
    foreach (['core', 'site'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    setupSitesTable();
    setupMediaTables();
```

Then update the first test (`writes the full brand design shape into site.settings.design end-to-end`) to assert on site_media rows instead of JSONB. Replace lines 150-169 with:

```php
it('writes brand design enums into site.settings.design and logos into site_media end-to-end', function () {
    $integration = seedBrandDesignJobFixtures();
    fakeBrandDesignJobShopify();

    SyncShopifyBrandDesignJob::dispatchSync($integration->id);

    $site = Site::query()->where('professional_id', 'pro-bdjob-1')->first();
    $design = $site->settings['design'] ?? [];

    // Enums + colours still live on settings.design — those aren't media.
    expect($design['colors']['background'])->toBe('#ababab');
    expect($design['colors']['text'])->toBe('#121212');
    expect($design['colors']['accent'])->toBe('#cd00ef');
    expect($design['colors']['border'])->toBeNull();
    expect($design['corner_radius'])->toBe('default');
    expect($design['slogan'])->toBe('Job test slogan');

    // Logos now live in site.site_media as pool=design / purpose=logo_full|logo_square.
    // The job no longer writes settings.design.logo — that key shouldn't exist.
    expect(array_key_exists('logo', $design))->toBeFalse();

    $logoRows = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->whereIn('purpose', [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE])
        ->whereNull('deleted_at')
        ->get();

    expect($logoRows)->toHaveCount(2);
    expect($logoRows->pluck('purpose')->sort()->values()->all())
        ->toBe([SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE]);
});
```

The second test (`preserves an existing user accent colour`) doesn't touch logos and stays as-is.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Shopify/SyncShopifyBrandDesignJobTest.php -v`
Expected: FAIL — the job still writes JSONB and doesn't create site_media rows.

- [ ] **Step 3: Refactor the job**

In `app/Jobs/Shopify/SyncShopifyBrandDesignJob.php`:

Add the imports near the top:

```php
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\BrandDesignMediaService;
```

Update the `handle` method to fetch bytes via a refactored `mirrorLogo` (now returns bytes + mime, not URL) and persist via the service. Replace lines 74-171 (`handle` and onwards through `failed`):

```php
    public function handle(BrandDesignImporter $importer, BrandDesignMediaService $brandDesign): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('id', $this->integrationId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return;
        }

        $site = Site::where('professional_id', $integration->professional_id)->first();

        if (! $site) {
            Log::warning('Brand design sync skipped — no site for professional.', [
                'integration_id' => $this->integrationId,
                'professional_id' => (string) $integration->professional_id,
            ]);

            return;
        }

        try {
            $imported = $importer->import($integration);
        } catch (\Throwable $e) {
            Log::warning('Brand design import failed.', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Download logos and persist via BrandDesignMediaService — the same
        // service the dashboard upload path uses. Failures are non-fatal:
        // a missing logo just means the existing site_media row stays intact.
        $this->persistLogoFromShopify(
            $brandDesign,
            $site,
            (string) $integration->professional_id,
            'full',
            is_string($imported['logo']['full_url'] ?? null) ? $imported['logo']['full_url'] : null,
        );
        $this->persistLogoFromShopify(
            $brandDesign,
            $site,
            (string) $integration->professional_id,
            'square',
            is_string($imported['logo']['square_url'] ?? null) ? $imported['logo']['square_url'] : null,
        );

        // Merge non-media design tokens into site.settings.design with
        // leave-if-absent semantics. Logo is intentionally NOT in this merge —
        // it lives in site_media now.
        $settings = is_array($site->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        $existingColors = is_array($design['colors'] ?? null) ? $design['colors'] : [];

        $design['colors'] = [
            'background' => $imported['colors']['background'] ?? ($existingColors['background'] ?? null),
            'text' => $imported['colors']['text'] ?? ($existingColors['text'] ?? null),
            'accent' => $imported['colors']['accent'] ?? ($existingColors['accent'] ?? null),
            'border' => $imported['colors']['border'] ?? ($existingColors['border'] ?? null),
        ];
        $design['corner_radius'] = $imported['corner_radius'] ?? ($design['corner_radius'] ?? null);
        $design['border_thickness'] = $imported['border_thickness'] ?? ($design['border_thickness'] ?? null);
        $design['section_spacing'] = $imported['section_spacing'] ?? ($design['section_spacing'] ?? null);
        $design['slogan'] = $imported['slogan'] ?? ($design['slogan'] ?? null);

        // Strip the legacy logo subtree if any older row still has it. The
        // backfill migration cleans existing rows; this keeps us idempotent
        // for any row that gets re-synced before the migration runs.
        unset($design['logo']);

        $settings['design'] = $design;
        $site->settings = $settings;
        $site->save();

        // Mirror the shape to a shop metafield. Best-effort — a failure here
        // doesn't invalidate the DB write.
        if (! empty($imported['shop_gid'])) {
            try {
                $this->writeBrandDesignMetafield($integration, (string) $imported['shop_gid'], [
                    'colors' => $design['colors'],
                    'corner_radius' => $design['corner_radius'],
                    'border_thickness' => $design['border_thickness'],
                    'section_spacing' => $design['section_spacing'],
                    'slogan' => $design['slogan'],
                    'synced_at' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to write sidest.brand_design metafield.', [
                    'integration_id' => $this->integrationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Cache::forget(CacheKeyGenerator::brandDesignConfig((string) $integration->professional_id));

        Log::info('Brand design synced.', [
            'integration_id' => $this->integrationId,
            'professional_id' => (string) $integration->professional_id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Shopify brand design sync permanently failed.', [
            'integration_id' => $this->integrationId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Download the logo bytes from Shopify CDN and hand them to
     * BrandDesignMediaService for persistence + variant generation. A null
     * sourceUrl or any HTTP failure is silently ignored — the existing
     * site_media row (if any) stays intact.
     */
    private function persistLogoFromShopify(
        BrandDesignMediaService $brandDesign,
        Site $site,
        string $professionalId,
        string $variant,
        ?string $sourceUrl,
    ): void {
        if (! is_string($sourceUrl) || $sourceUrl === '' || ! str_starts_with($sourceUrl, 'https://')) {
            return;
        }

        try {
            $response = Http::timeout(20)
                ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])
                ->get($sourceUrl);

            if (! $response->ok()) {
                return;
            }

            $bytes = $response->body();
            if ($bytes === '') {
                return;
            }

            $mime = (string) $response->header('Content-Type') ?: 'image/png';

            $brandDesign->upsertLogoFromBytes($site, $professionalId, $bytes, $mime, $variant);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist Shopify-mirrored brand logo.', [
                'integration_id' => $this->integrationId,
                'variant' => $variant,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

Delete the now-unused `mirrorLogo`, `extensionFromContentType`, and `mediaDiskName` private methods (originally lines 187-261). The service handles disk resolution and content-type extension internally.

- [ ] **Step 4: Run the Shopify sync tests**

Run: `vendor/bin/pest tests/Feature/Shopify/SyncShopifyBrandDesignJobTest.php -v`
Expected: PASS. The "writes the full brand design shape" test now asserts on site_media rows; the "preserves accent" test still passes because that path doesn't touch logos.

- [ ] **Step 5: Run the full test suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/Shopify/SyncShopifyBrandDesignJob.php tests/Feature/Shopify/SyncShopifyBrandDesignJobTest.php
git commit -m "$(cat <<'EOF'
refactor(shopify): persist Shopify-mirrored logos via BrandDesignMediaService

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Drop JSONB validation rules and merge logic

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php`
- Modify: `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php`
- Modify: `app/Actions/Site/UpdateSiteAction.php`

After this task, no client request can repopulate the dead JSONB keys, and the merge action no longer treats `placeholder_sitepage_images` as a special list to overwrite (it isn't in the JSONB anymore).

- [ ] **Step 1: Update UpdateSiteRequest**

In `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php`:

Remove the four placeholder validation rules (lines 70-73):

```php
            'settings.design.media.placeholder_sitepage_images' => ['sometimes', 'array', 'max:5'],
            'settings.design.media.placeholder_sitepage_images.*.name' => ['required_with:settings.design.media.placeholder_sitepage_images', 'string', 'max:255'],
            'settings.design.media.placeholder_sitepage_images.*.path' => ['required_with:settings.design.media.placeholder_sitepage_images', 'string', 'max:2048'],
            'settings.design.media.placeholder_sitepage_images.*.url' => ['required_with:settings.design.media.placeholder_sitepage_images', 'url', 'max:2048'],
```

Replace with `prohibited` rules so old clients get a clear 422 instead of a silent acceptance:

```php
            'settings.design.media.placeholder_sitepage_images' => ['prohibited'],
            'settings.design.media.placeholder_sitepage_images.*' => ['prohibited'],
```

Then locate the existing logo rules (lines 96-98):

```php
            'settings.design.logo' => ['sometimes', 'array'],
            'settings.design.logo.full_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.design.logo.square_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
```

Replace with:

```php
            'settings.design.logo' => ['prohibited'],
            'settings.design.logo.full_url' => ['prohibited'],
            'settings.design.logo.square_url' => ['prohibited'],
```

In the `messages()` array (around line 193), add prohibited messages so the error explains where to write instead. Add these entries before the closing `];`:

```php
            'settings.design.media.placeholder_sitepage_images.prohibited' => 'Use /api/uploads/brand-placeholder-image and the brand-placeholder-images management endpoints.',
            'settings.design.media.placeholder_sitepage_images.*.prohibited' => 'Use /api/uploads/brand-placeholder-image and the brand-placeholder-images management endpoints.',
            'settings.design.logo.prohibited' => 'Use /api/uploads/brand-logo (managed by site_media).',
            'settings.design.logo.full_url.prohibited' => 'Use /api/uploads/brand-logo (managed by site_media).',
            'settings.design.logo.square_url.prohibited' => 'Use /api/uploads/brand-logo (managed by site_media).',
```

- [ ] **Step 2: Update StaffUpdateSiteRequest**

In `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php`:

Remove lines 115-118 (the four placeholder rules) and replace with prohibited variants. Locate:

```php
            'settings.design.media.placeholder_sitepage_images' => ['sometimes', 'array', 'max:5'],
            'settings.design.media.placeholder_sitepage_images.*.name' => ['required_with:settings.design.media.placeholder_sitepage_images', 'string', 'max:255'],
            'settings.design.media.placeholder_sitepage_images.*.path' => ['required_with:settings.design.media.placeholder_sitepage_images', 'string', 'max:2048'],
            'settings.design.media.placeholder_sitepage_images.*.url' => ['required_with:settings.design.media.placeholder_sitepage_images', 'url', 'max:2048'],
```

Replace with:

```php
            'settings.design.media.placeholder_sitepage_images' => ['prohibited'],
            'settings.design.media.placeholder_sitepage_images.*' => ['prohibited'],
```

This file does not currently validate `settings.design.logo`, so no further changes are needed for the logo block. Add a prohibited rule anyway so staff updates can't sneak in JSONB writes either. Add this line right after the placeholder prohibited rules:

```php
            'settings.design.logo' => ['prohibited'],
```

- [ ] **Step 3: Update UpdateSiteAction**

In `app/Actions/Site/UpdateSiteAction.php`:

Remove the placeholder list-key special case (lines 127-141). Locate:

```php
            // Indexed lists under settings must be replaced wholesale, not merged.
            // array_replace_recursive merges by key, so sending a shortened list
            // leaves stale trailing entries in the DB (e.g. deleting a placeholder
            // image in the UI would "come back" on refresh because its index was
            // preserved from $existing). Explicitly overwrite these keys with the
            // incoming value whenever the client provided one.
            $listKeys = [
                'design.media.placeholder_sitepage_images',
            ];
            foreach ($listKeys as $listKey) {
                if (Arr::has($incoming, $listKey)) {
                    Arr::set($merged, $listKey, Arr::get($incoming, $listKey));
                }
            }
```

Replace with a comment explaining why the loop is gone:

```php
            // Indexed list special-casing was historically needed for
            // settings.design.media.placeholder_sitepage_images, which has
            // since moved to site.site_media. No remaining indexed lists live
            // under settings, so the recursive merge is sufficient.
```

- [ ] **Step 4: Run the test suite**

Run: `composer test`
Expected: PASS. Any test that POSTs to `/api/professional/site` with `settings.design.logo` or `settings.design.media.placeholder_sitepage_images` in the body will now fail with a 422; if such tests exist, update them to use the upload endpoints instead.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php app/Actions/Site/UpdateSiteAction.php
git commit -m "$(cat <<'EOF'
refactor(site): prohibit JSONB writes for brand logo + placeholders

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Backfill migration — JSONB → site_media + strip JSONB

**Files:**
- Create: `supabase/migrations/20260415120100_backfill_brand_design_media.sql`

This migration does two things, in order:
1. For any site with `settings.design.media.placeholder_sitepage_images` populated and no matching site_media rows, leave a warning (we can't generate variants from a URL — the original bytes are gone). For pre-beta this will only affect rows that were never re-uploaded after the schema landed, which the user has already verified isn't an issue (the dev brand "Side St" has site_media rows that match the JSONB URLs).
2. Strip `settings.design.logo` and `settings.design.media.placeholder_sitepage_images` from the JSONB on every site, regardless.

Because pre-beta has no real user data and the user has explicitly skipped phasing, this is a destructive backfill. The migration logs a NOTICE for any site where the JSONB had data we couldn't migrate so it's visible in the migration log.

- [ ] **Step 1: Create the migration**

Create `supabase/migrations/20260415120100_backfill_brand_design_media.sql`:

```sql
-- Strip brand design media keys from site.sites.settings now that site_media
-- is the source of truth.
--
-- Why: after this migration, nothing reads settings.design.logo or
-- settings.design.media.placeholder_sitepage_images. Leaving them in place
-- creates drift risk and confuses future readers — the JSONB would say one
-- thing and the site_media table would say another.
--
-- Pre-beta context: dashboards already write through site_media via the upload
-- endpoints, and SyncShopifyBrandDesignJob was updated in the same change set
-- to write site_media too. Existing rows in production were already covered
-- when the user verified site_media has the matching design pool entries; this
-- migration finishes the cutover by clearing the dead JSONB keys.

BEGIN;

-- Step 1 — Surface any site whose JSONB had placeholder URLs but no matching
-- site_media row, so a human notices if there's pre-existing drift. NOTICE
-- only — does not abort.
DO $$
DECLARE
    drift_row record;
    drift_count int := 0;
BEGIN
    FOR drift_row IN
        SELECT s.id AS site_id, s.professional_id
        FROM site.sites s
        WHERE s.settings IS NOT NULL
          AND jsonb_typeof(s.settings) = 'object'
          AND jsonb_array_length(COALESCE(s.settings->'design'->'media'->'placeholder_sitepage_images', '[]'::jsonb)) > 0
          AND NOT EXISTS (
              SELECT 1 FROM site.site_media sm
              WHERE sm.site_id = s.id
                AND sm.pool = 'design'
                AND sm.purpose = 'placeholder'
                AND sm.deleted_at IS NULL
          )
    LOOP
        RAISE NOTICE 'Backfill drift: site % (professional %) has JSONB placeholders but no site_media rows.',
            drift_row.site_id, drift_row.professional_id;
        drift_count := drift_count + 1;
    END LOOP;

    IF drift_count > 0 THEN
        RAISE NOTICE 'Total drift sites: %', drift_count;
    END IF;
END $$;

-- Step 2 — Same drift check for logos.
DO $$
DECLARE
    drift_row record;
    drift_count int := 0;
BEGIN
    FOR drift_row IN
        SELECT s.id AS site_id, s.professional_id
        FROM site.sites s
        WHERE s.settings IS NOT NULL
          AND jsonb_typeof(s.settings) = 'object'
          AND (
              s.settings->'design'->'logo'->>'full_url' IS NOT NULL
              OR s.settings->'design'->'logo'->>'square_url' IS NOT NULL
          )
          AND NOT EXISTS (
              SELECT 1 FROM site.site_media sm
              WHERE sm.site_id = s.id
                AND sm.pool = 'design'
                AND sm.purpose IN ('logo_full', 'logo_square')
                AND sm.deleted_at IS NULL
          )
    LOOP
        RAISE NOTICE 'Backfill drift: site % (professional %) has JSONB logo but no site_media rows.',
            drift_row.site_id, drift_row.professional_id;
        drift_count := drift_count + 1;
    END LOOP;

    IF drift_count > 0 THEN
        RAISE NOTICE 'Total drift sites: %', drift_count;
    END IF;
END $$;

-- Step 3 — Strip placeholder_sitepage_images and brand_logo_* from settings.design.media.
UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design,media}',
    COALESCE(settings->'design'->'media', '{}'::jsonb)
        - 'placeholder_sitepage_images'
        - 'brand_logo_url'
        - 'brand_logo_path'
        - 'brand_logo_name',
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND settings->'design'->'media' IS NOT NULL;

-- Step 4 — Strip logo subtree from settings.design.
UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design}',
    (settings->'design') - 'logo',
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND settings->'design' ? 'logo';

COMMIT;
```

- [ ] **Step 2: Apply the migration locally to confirm it parses**

The composer guard rejects Laravel migration files but supabase migrations are just SQL — they're applied via the Supabase CLI or against the dev project. For this plan step, just verify the file is well-formed PostgreSQL by running it through `psql --dry-run` if available, or by manually reviewing each statement.

Run: `composer test`
Expected: PASS. Tests don't apply Supabase migrations (they use the in-memory SQLite schema bootstrap), so this is a sanity check that nothing else regressed.

- [ ] **Step 3: Commit**

```bash
git add supabase/migrations/20260415120100_backfill_brand_design_media.sql
git commit -m "$(cat <<'EOF'
chore(db): strip dead brand-design JSONB keys after site_media cutover

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Verification + Nightwatch check

**Files:** none (verification only)

After all the above lands, do a final sweep to confirm nothing reads the dead JSONB keys.

- [ ] **Step 1: grep for any remaining JSONB readers**

Run: `grep -rn "design.*placeholder_sitepage_images\|design'\]\['logo'\]\['full_url\|design.logo.full_url\|design.logo.square_url" app/ tests/ | grep -v -E "tests/Feature/Brand/BrandDesignControllerTest|legacy|migration"`

Expected: only test files that exercise the Hydrogen response shape (which is fine — Hydrogen still returns logo + placeholder_sitepage_images, just sourced from site_media).

If any production code path still reads `design.logo` or `design.media.placeholder_sitepage_images`, follow up by routing it through `BrandDesignMediaService` and re-running `composer test`.

- [ ] **Step 2: Run the full suite one more time**

Run: `composer test`
Expected: PASS.

- [ ] **Step 3: Smoke-test the dev DB against the new shape**

In a separate terminal, against the V2 dev project (`glncumufgaqcmqhzwrxm`) on Supabase, run a query to confirm the dev brand "Side St" still has its design rows after the schema change. The plan author already verified this site has logo + placeholder rows in site_media.

```sql
SELECT id, pool, purpose, alt_text, processing_state, sort_order
FROM site.site_media
WHERE site_id = '019d58b6-4248-72e1-bed9-43b35ae33b2a'
  AND pool = 'design'
  AND deleted_at IS NULL
ORDER BY purpose, sort_order;
```

Expected after the deploy: 1 row with `purpose='logo_full'`, possibly 1 row with `purpose='logo_square'` (if Shopify resync has run), and 1+ rows with `purpose='placeholder'`. The Side St brand currently has 1 active placeholder and 1 active logo_full, so expect at least those.

- [ ] **Step 4: Check Nightwatch after deploy**

After Laravel Cloud auto-deploys from `development-v2`, check Nightwatch for any new exceptions in:
- `App\Http\Controllers\Api\Internal\HydrogenBrandDesignController` — Hydrogen reads
- `App\Http\Controllers\Api\Professional\Store\BrandDesignController` — dashboard reads
- `App\Services\Cache\SiteCacheService` — affiliate fallback
- `App\Jobs\Shopify\SyncShopifyBrandDesignJob` — sync writes

Expected: zero new exceptions. If anything spikes, file an issue and revert the offending controller change first (the migrations are safe to leave in place).

- [ ] **Step 5: Final commit (if any test cleanup is needed)**

```bash
git status
# If there are stray test fixtures or lint-fixed files, stage and commit them.
```

---

## Self-Review

**Spec coverage:**
- ✅ Placeholder uploads no longer soft-delete prior rows (Task 2 service `addPlaceholder` + Task 3 controller delegation).
- ✅ `purpose` discriminator column added (Task 1).
- ✅ `sort_order` properly assigned, max-5 cap enforced (Task 2 + Task 4).
- ✅ Reorder + delete endpoints + per-slot delete (Task 4).
- ✅ `extractPlaceholderImages` rewritten to read from site_media (Task 5).
- ✅ Validation requests stop accepting `placeholder_sitepage_images` (Task 9).
- ✅ Logos use `purpose='logo_full'` / `'logo_square'` (Task 1 column + Task 2 service).
- ✅ `HydrogenBrandDesignController::buildDesignPayload` reads logo from site_media (Task 5).
- ✅ `BrandDesignController::show` reads logo from site_media (Task 6).
- ✅ JSONB `settings.design.logo` writes dropped (Task 8 Shopify sync + Task 9 validators).
- ✅ `SyncShopifyBrandDesignJob::mirrorLogo` replaced with `BrandDesignMediaService::upsertLogoFromBytes` call path (Task 8).
- ✅ Conflict rule preserved — null `sourceUrl` is silently ignored, doesn't nuke existing rows (Task 8 `persistLogoFromShopify`).
- ✅ Backfill migration strips dead JSONB keys with NOTICE warnings for any drift (Task 10).
- ✅ `SiteCacheService::applyBrandImageFallbacks` rewritten (Task 7).

**Out of spec (deferred):**
- Renaming `alt_text` to be a real a11y text field. Today the upload service stores the file's original filename in `alt_text` for placeholders so the dashboard has something to display; a future cleanup can split this into separate `alt_text` (a11y) and `display_name` columns.

**Placeholder scan:**
- No "TODO", no "implement later", no naked "Add validation". Each step has full code or full commands.

**Type consistency:**
- `BrandDesignMediaService::listDesignMedia()` always returns `{logo: {full_url, square_url}, placeholders: [{id, alt_text, url, sort_order}]}`. All three callers (Hydrogen, dashboard, cache) consume the same shape and project the fields they need.
- `purpose` column values are always `logo_full | logo_square | placeholder | NULL`. Constants on `SiteMedia` enforce this.
- Hydrogen response: `placeholders: [{url, alt_text}]` (renamed from `placeholder_sitepage_images`, drops `name`/`path`).
- Dashboard response: `placeholders: [{id, alt_text, url, sort_order}]` (full shape since the dashboard manages them).
- Affiliate fallback (SiteCacheService): `[{url, alt_text}]` injected into `gallery`/`content_images` slots when the affiliate has no media of their own.
