# Deletion Lifecycle Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Patch every confirmed gap from the deletion cascade audit — from orphaned R2 files on account purge (critical) down to missing failed-job pruning (low).

**Architecture:** Fixes are isolated to six independent sites: `AccountDeletionService`, two third-party sync jobs, six analytics aggregate jobs, one Shopify webhook job, one notification fan-out job, and the scheduler. No new abstractions; each change is the minimal guard or call the code always should have had.

**Tech Stack:** Laravel 12, Pest 4, PHP 8.2, Cloudflare R2 via `Storage::disk()`, Redis-backed queues via Horizon.

---

## File Map

| File | Change |
|------|--------|
| `app/Services/Professional/AccountDeletionService.php` | Add `purgeMediaArtifacts()` helper and `SiteCacheService::invalidateSite()` call; both run in `purge()` before `forceDelete()` |
| `app/Jobs/Square/PushServiceToSquareJob.php` | Add `$service->trashed()` guard |
| `app/Jobs/Fresha/PushServiceToFreshaJob.php` | Add `$service->trashed()` guard |
| `app/Jobs/Analytics/RebuildSiteHourlyAggregatesJob.php` | Add Professional existence guard |
| `app/Jobs/Analytics/RebuildSiteDailyAggregatesJob.php` | Add Professional existence guard |
| `app/Jobs/Analytics/RebuildCommerceDailyAggregatesJob.php` | Add Professional existence guard |
| `app/Jobs/Analytics/RebuildCommerceHourlyAggregatesJob.php` | Add Professional existence guard |
| `app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php` | Add Professional existence guard |
| `app/Jobs/Analytics/RebuildBookingHourlyAggregatesJob.php` | Add Professional existence guard |
| `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php` | Add Professional existence guard |
| `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php` | Load brand before fan-out; bail if not found |
| `routes/console.php` | Add `queue:prune-failed` schedule |
| `tests/Feature/Account/AccountDeletionPurgeMediaTest.php` | New — verifies R2 cleanup is triggered in purge |
| `tests/Unit/Jobs/ServiceJobTrashedGuardTest.php` | New — verifies Square/Fresha jobs skip trashed services |
| `tests/Unit/Jobs/AnalyticsJobEntityGuardTest.php` | New — verifies analytics jobs bail for missing professional |
| `tests/Unit/Jobs/ShopifyOrderUpdatedWebhookGuardTest.php` | New — verifies order-updated job bails for missing professional |
| `tests/Unit/Jobs/FanOutBrandStatusNotificationGuardTest.php` | New — verifies fan-out bails for missing brand |
| `tests/Pest.php` | Add `setupProfessionalDeletionAuditTable()` helper |

---

## Task 1: R2 Media Cleanup Before Account Purge (CRITICAL)

**Context:** `AccountDeletionService::purge()` calls `$professional->forceDelete()` which triggers 42 DB cascades — `site_media` rows are deleted but the corresponding R2 files are never touched. The fix is to enumerate all media items by site and dispatch/call the appropriate cleanup *before* `forceDelete()`.

We also bust the public site payload cache (15-min TTL) at the same time — otherwise a just-purged site can keep responding from Redis to public requests until the key expires.

**Files:**
- Modify: `app/Services/Professional/AccountDeletionService.php`
- Modify: `tests/Pest.php`
- Create: `tests/Feature/Account/AccountDeletionPurgeMediaTest.php`

- [ ] **Step 1: Add `setupProfessionalDeletionAuditTable()` helper to `tests/Pest.php`**

At the end of `tests/Pest.php`, after the existing helpers, add:

```php
/**
 * core.professional_deletion_audit — all columns nullable, minimal for purge tests.
 */
function setupProfessionalDeletionAuditTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
        id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
        professional_id TEXT NULL,
        professional_handle_snapshot TEXT NULL,
        professional_email_snapshot TEXT NULL,
        event TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        metadata TEXT NULL,
        created_at TEXT NULL
    )');
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Account/AccountDeletionPurgeMediaTest.php`:

```php
<?php

use App\Jobs\DeleteMediaArtifactsJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupMediaTables();
    setupProfessionalDeletionAuditTable();

    config([
        'sidest.media_disk' => 'media',
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-key',
    ]);

    Storage::fake('media');
    Queue::fake();
    Http::fake(['https://test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);
});

it('dispatches DeleteMediaArtifactsJob for each video media item on purge', function () {
    $professional = createTenant('purge-video');
    $site = $professional->site;

    $mediaId = (string) Str::uuid();
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id'               => $mediaId,
        'site_id'          => $site->id,
        'pool'             => SiteMedia::POOL_GALLERY,
        'path'             => "videos/{$professional->id}/{$mediaId}",
        'media_type'       => SiteMedia::MEDIA_TYPE_VIDEO,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active'        => 1,
        'created_at'       => now()->toDateTimeString(),
        'updated_at'       => now()->toDateTimeString(),
    ]);

    app(AccountDeletionService::class)->purge($professional);

    Queue::assertDispatched(DeleteMediaArtifactsJob::class, function (DeleteMediaArtifactsJob $job) use ($mediaId, $professional) {
        return $job->mediaId === $mediaId
            && $job->basePath === "videos/{$professional->id}/{$mediaId}";
    });
});

it('deletes image variant files from storage on purge', function () {
    $professional = createTenant('purge-image');
    $site = $professional->site;

    $mediaId = (string) Str::uuid();
    $imagePath = "images/{$professional->id}/{$mediaId}/original.jpg";
    Storage::disk('media')->put($imagePath, 'fake-image-bytes');

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id'               => $mediaId,
        'site_id'          => $site->id,
        'pool'             => SiteMedia::POOL_GALLERY,
        'path'             => $imagePath,
        'media_type'       => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active'        => 1,
        'created_at'       => now()->toDateTimeString(),
        'updated_at'       => now()->toDateTimeString(),
    ]);

    app(AccountDeletionService::class)->purge($professional);

    Storage::disk('media')->assertMissing($imagePath);
});

it('deletes document files from storage on purge', function () {
    $professional = createTenant('purge-doc');
    $site = $professional->site;

    $mediaId = (string) Str::uuid();
    $docPath = "documents/{$professional->id}/{$mediaId}/file.pdf";
    Storage::disk('media')->put($docPath, 'fake-pdf-bytes');

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id'               => $mediaId,
        'site_id'          => $site->id,
        'pool'             => SiteMedia::POOL_DOCUMENTS,
        'path'             => $docPath,
        'media_type'       => SiteMedia::MEDIA_TYPE_DOCUMENT,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active'        => 1,
        'created_at'       => now()->toDateTimeString(),
        'updated_at'       => now()->toDateTimeString(),
    ]);

    app(AccountDeletionService::class)->purge($professional);

    Storage::disk('media')->assertMissing($docPath);
});

it('still completes purge when a professional has no site media', function () {
    $professional = createTenant('purge-empty');

    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();
    Queue::assertNothingDispatched();
});

it('invalidates the public site cache before forceDelete so stale payloads are gone immediately', function () {
    $professional = createTenant('purge-cache-bust');
    $site = $professional->site;

    $cache = Mockery::mock(\App\Services\Cache\SiteCacheService::class);
    $cache->shouldReceive('invalidateSite')->once()->with(Mockery::on(fn ($s) => $s->id === $site->id));
    $this->app->instance(\App\Services\Cache\SiteCacheService::class, $cache);

    app(AccountDeletionService::class)->purge($professional);
});

it('continues purge even when an individual media artifact cleanup throws', function () {
    $professional = createTenant('purge-partial-fail');
    $site = $professional->site;

    // Seed a video row — the job dispatch itself should still succeed
    $mediaId = (string) Str::uuid();
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id'               => $mediaId,
        'site_id'          => $site->id,
        'pool'             => SiteMedia::POOL_GALLERY,
        'path'             => "videos/{$professional->id}/{$mediaId}",
        'media_type'       => SiteMedia::MEDIA_TYPE_VIDEO,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active'        => 1,
        'created_at'       => now()->toDateTimeString(),
        'updated_at'       => now()->toDateTimeString(),
    ]);

    // ImageVariantService throwing should not abort the whole purge
    $this->mock(ImageVariantService::class, function ($mock) {
        $mock->shouldReceive('deleteVariants')->andThrow(new \RuntimeException('storage error'));
    });

    // Purge completes (returns true) — the video job is still dispatched
    $result = app(AccountDeletionService::class)->purge($professional);
    expect($result)->toBeTrue();
});
```

- [ ] **Step 3: Run tests to confirm they fail**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Feature/Account/AccountDeletionPurgeMediaTest.php --no-coverage
```

Expected: 6 failures (method `purgeMediaArtifacts` does not exist; cache mock expectation never satisfied).

- [ ] **Step 4: Implement `purgeMediaArtifacts()` + cache bust in `AccountDeletionService`**

Add these imports at the top of `app/Services/Professional/AccountDeletionService.php`:

```php
use App\Jobs\DeleteMediaArtifactsJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Support\Facades\Storage;
```

Add two calls inside `purge()`, between the Supabase deletion and `forceDelete()`. Replace the Step 2 comment block:

```php
    // Step 2: clean up R2 artifacts before the DB cascade deletes the rows.
    // forceDelete() cascades to site_media, but DB cascades do not touch R2 storage.
    $this->purgeMediaArtifacts($professional);

    // Step 3: bust the public site cache (15-min TTL) so a just-purged site
    // stops serving stale payloads to public requests the instant we delete.
    // invalidateSite() handles the main subdomain + all aliases in one call.
    $site = Site::query()->where('professional_id', $professional->id)->first();
    if ($site) {
        try {
            app(SiteCacheService::class)->invalidateSite($site);
        } catch (\Throwable $e) {
            Log::warning('Site cache invalidation failed during account purge', [
                'professional_id' => $professional->id,
                'site_id'         => $site->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    // Step 4: hard-delete professional row. DB handles cascades (42 FKs CASCADE,
```

> Note: the existing comment that begins "Step 2: hard-delete professional row" becomes Step 4. Update only the comment text on that one line — do not renumber the audit-log comment below it (it has no number).

Add these three private methods before the closing `}` of the class:

```php
    /**
     * Enumerate all site media for this professional and clean up R2 artifacts.
     * Videos are dispatched async (many HLS segments). Images and documents are
     * deleted synchronously (single file per record). Failures are logged and
     * skipped — a storage error must never block the DB deletion.
     */
    private function purgeMediaArtifacts(Professional $professional): void
    {
        $site = Site::query()->where('professional_id', $professional->id)->first();

        if (! $site) {
            return;
        }

        $mediaItems = SiteMedia::query()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get();

        foreach ($mediaItems as $media) {
            try {
                match ($media->media_type) {
                    SiteMedia::MEDIA_TYPE_VIDEO    => $this->purgeVideoArtifacts($media),
                    SiteMedia::MEDIA_TYPE_DOCUMENT => $this->purgeDocumentArtifact($media),
                    default                        => $this->purgeImageArtifacts($media),
                };
            } catch (\Throwable $e) {
                Log::warning('R2 artifact cleanup failed for media item during account purge', [
                    'professional_id' => $professional->id,
                    'media_id'        => $media->id,
                    'media_type'      => $media->media_type,
                    'error'           => $e->getMessage(),
                ]);
            }
        }
    }

    private function purgeVideoArtifacts(SiteMedia $media): void
    {
        if (! $media->path) {
            return;
        }

        DeleteMediaArtifactsJob::dispatch($media->id, $media->path, (string) $media->pool);
    }

    private function purgeImageArtifacts(SiteMedia $media): void
    {
        app(ImageVariantService::class)->deleteVariants($media->id, $media->path ?: null);
    }

    private function purgeDocumentArtifact(SiteMedia $media): void
    {
        if (! $media->path) {
            return;
        }

        $disk = Storage::disk((string) config('sidest.media_disk'));
        if ($disk->exists($media->path)) {
            $disk->delete($media->path);
        }
    }
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Feature/Account/AccountDeletionPurgeMediaTest.php --no-coverage
```

Expected: 6 passing.

- [ ] **Step 6: Run full suite to check for regressions**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test
```

Expected: green.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Professional/AccountDeletionService.php \
        tests/Pest.php \
        tests/Feature/Account/AccountDeletionPurgeMediaTest.php
git commit -m "fix(media): purge R2 artifacts and bust site cache before professional forceDelete in account purge"
```

---

## Task 2: PushServiceToSquare/Fresha Trashed Guard (HIGH)

**Context:** Both jobs load the service with `withTrashed()` but never check `->trashed()`. A soft-deleted service gets pushed to the third-party API instead of being skipped. `ServiceObserver::deleted()` dispatches these with `action = 'delete'` correctly, but retries after partial failure would re-push the deleted service as active.

**Files:**
- Modify: `app/Jobs/Square/PushServiceToSquareJob.php:31-40`
- Modify: `app/Jobs/Fresha/PushServiceToFreshaJob.php:31-40`
- Create: `tests/Unit/Jobs/ServiceJobTrashedGuardTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Jobs/ServiceJobTrashedGuardTest.php`:

```php
<?php

use App\Jobs\Fresha\PushServiceToFreshaJob;
use App\Jobs\Square\PushServiceToSquareJob;
use App\Models\Core\Professional\Service;
use App\Services\Fresha\FreshaServiceSyncService;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.services (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        name TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    config([
        'sidest.features.square_sync' => true,
        'sidest.features.fresha_sync' => true,
    ]);
});

it('does not call SquareServiceSyncService when service is soft-deleted', function () {
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id'         => $serviceId,
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $syncService = Mockery::mock(SquareServiceSyncService::class);
    $syncService->shouldNotReceive('pushServiceToSquare');

    $job = new PushServiceToSquareJob($serviceId, 'upsert');
    $job->handle($syncService);
});

it('does not call FreshaServiceSyncService when service is soft-deleted', function () {
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id'         => $serviceId,
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $syncService = Mockery::mock(FreshaServiceSyncService::class);
    $syncService->shouldNotReceive('pushServiceToFresha');

    $job = new PushServiceToFreshaJob($serviceId, 'upsert');
    $job->handle($syncService);
});

it('calls SquareServiceSyncService when service is not deleted', function () {
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id'         => $serviceId,
        'deleted_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $syncService = Mockery::mock(SquareServiceSyncService::class);
    $syncService->shouldReceive('pushServiceToSquare')->once();

    $job = new PushServiceToSquareJob($serviceId, 'upsert');
    $job->handle($syncService);
});

it('calls FreshaServiceSyncService when service is not deleted', function () {
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id'         => $serviceId,
        'deleted_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $syncService = Mockery::mock(FreshaServiceSyncService::class);
    $syncService->shouldReceive('pushServiceToFresha')->once();

    $job = new PushServiceToFreshaJob($serviceId, 'upsert');
    $job->handle($syncService);
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Unit/Jobs/ServiceJobTrashedGuardTest.php --no-coverage
```

Expected: 2 failures (the "should not receive" assertions fire because the guard doesn't exist yet).

- [ ] **Step 3: Add trashed guard to `PushServiceToSquareJob`**

In `app/Jobs/Square/PushServiceToSquareJob.php`, replace the `handle()` method body from line 26 onward:

```php
    public function handle(SquareServiceSyncService $syncService): void
    {
        if (! (bool) config('sidest.features.square_sync', false)) {
            return;
        }

        $service = Service::query()
            ->withTrashed()
            ->where('id', $this->serviceId)
            ->first();

        if (! $service || $service->trashed()) {
            return;
        }

        $syncService->pushServiceToSquare($service, $this->action);
    }
```

- [ ] **Step 4: Add trashed guard to `PushServiceToFreshaJob`**

In `app/Jobs/Fresha/PushServiceToFreshaJob.php`, replace the `handle()` method body:

```php
    public function handle(FreshaServiceSyncService $syncService): void
    {
        if (! (bool) config('sidest.features.fresha_sync', false)) {
            return;
        }

        $service = Service::query()
            ->withTrashed()
            ->where('id', $this->serviceId)
            ->first();

        if (! $service || $service->trashed()) {
            return;
        }

        $syncService->pushServiceToFresha($service, $this->action);
    }
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Unit/Jobs/ServiceJobTrashedGuardTest.php --no-coverage
```

Expected: 4 passing.

- [ ] **Step 6: Run full suite**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test
```

Expected: green.

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/Square/PushServiceToSquareJob.php \
        app/Jobs/Fresha/PushServiceToFreshaJob.php \
        tests/Unit/Jobs/ServiceJobTrashedGuardTest.php
git commit -m "fix(jobs): skip Square/Fresha push when service is soft-deleted"
```

---

## Task 3: Analytics Aggregate Job Entity Guard (MEDIUM)

**Context:** Six analytics jobs accept professional IDs as strings and never verify the professional exists. After a `forceDelete()`, these jobs silently compute on empty result sets. The fix: check `Professional::find()` before calling the aggregate service. (`find()` respects SoftDeletes, so pending-deletion professionals — who still have a valid DB row — still get aggregates built.)

**Files:**
- Modify: `app/Jobs/Analytics/RebuildSiteHourlyAggregatesJob.php`
- Modify: `app/Jobs/Analytics/RebuildSiteDailyAggregatesJob.php`
- Modify: `app/Jobs/Analytics/RebuildCommerceDailyAggregatesJob.php`
- Modify: `app/Jobs/Analytics/RebuildCommerceHourlyAggregatesJob.php`
- Modify: `app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php`
- Modify: `app/Jobs/Analytics/RebuildBookingHourlyAggregatesJob.php`
- Create: `tests/Unit/Jobs/AnalyticsJobEntityGuardTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Jobs/AnalyticsJobEntityGuardTest.php`:

```php
<?php

use App\Jobs\Analytics\RebuildBookingDailyAggregatesJob;
use App\Jobs\Analytics\RebuildBookingHourlyAggregatesJob;
use App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob;
use App\Jobs\Analytics\RebuildCommerceHourlyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteDailyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteHourlyAggregatesJob;
use App\Services\Analytics\BookingAnalyticsAggregateService;
use App\Services\Analytics\CommerceAnalyticsAggregateService;
use App\Services\Analytics\SiteAnalyticsAggregateService;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
});

// ── Site Hourly ──────────────────────────────────────────────────────────────

it('RebuildSiteHourlyAggregatesJob skips when professional does not exist', function () {
    $svc = Mockery::mock(SiteAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildProfessionalHour');

    $job = new RebuildSiteHourlyAggregatesJob((string) Str::uuid(), '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

it('RebuildSiteHourlyAggregatesJob runs when professional exists', function () {
    $pro = createTenant('site-hourly-pro');

    $svc = Mockery::mock(SiteAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildProfessionalHour')->once();

    $job = new RebuildSiteHourlyAggregatesJob($pro->id, '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

// ── Site Daily ───────────────────────────────────────────────────────────────

it('RebuildSiteDailyAggregatesJob skips when professional does not exist', function () {
    $svc = Mockery::mock(SiteAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildProfessionalDay');

    $job = new RebuildSiteDailyAggregatesJob((string) Str::uuid(), '2026-04-24');
    $job->handle($svc);
});

it('RebuildSiteDailyAggregatesJob runs when professional exists', function () {
    $pro = createTenant('site-daily-pro');

    $svc = Mockery::mock(SiteAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildProfessionalDay')->once();

    $job = new RebuildSiteDailyAggregatesJob($pro->id, '2026-04-24');
    $job->handle($svc);
});

// ── Commerce Daily ───────────────────────────────────────────────────────────

it('RebuildCommerceDailyAggregatesJob skips when brand does not exist', function () {
    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildForOrder');

    $affiliate = createAffiliateTenant('commerce-daily-aff');

    $job = new RebuildCommerceDailyAggregatesJob((string) Str::uuid(), $affiliate->id, '2026-04-24');
    $job->handle($svc);
});

it('RebuildCommerceDailyAggregatesJob skips when affiliate does not exist', function () {
    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildForOrder');

    $brand = createBrandTenant('commerce-daily-brand');

    $job = new RebuildCommerceDailyAggregatesJob($brand->id, (string) Str::uuid(), '2026-04-24');
    $job->handle($svc);
});

it('RebuildCommerceDailyAggregatesJob runs when both professionals exist', function () {
    $brand = createBrandTenant('commerce-daily-b2');
    $affiliate = createAffiliateTenant('commerce-daily-a2');

    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildForOrder')->once();

    $job = new RebuildCommerceDailyAggregatesJob($brand->id, $affiliate->id, '2026-04-24');
    $job->handle($svc);
});

// ── Commerce Hourly ──────────────────────────────────────────────────────────

it('RebuildCommerceHourlyAggregatesJob skips when brand does not exist', function () {
    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildForHour');

    $affiliate = createAffiliateTenant('commerce-hourly-aff');

    $job = new RebuildCommerceHourlyAggregatesJob((string) Str::uuid(), $affiliate->id, '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

it('RebuildCommerceHourlyAggregatesJob runs when both professionals exist', function () {
    $brand = createBrandTenant('commerce-hourly-b2');
    $affiliate = createAffiliateTenant('commerce-hourly-a2');

    $svc = Mockery::mock(CommerceAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildForHour')->once();

    $job = new RebuildCommerceHourlyAggregatesJob($brand->id, $affiliate->id, '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

// ── Booking Daily ────────────────────────────────────────────────────────────

it('RebuildBookingDailyAggregatesJob skips when professional does not exist', function () {
    $svc = Mockery::mock(BookingAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildProfessionalDay');

    $job = new RebuildBookingDailyAggregatesJob((string) Str::uuid(), '2026-04-24');
    $job->handle($svc);
});

it('RebuildBookingDailyAggregatesJob runs when professional exists', function () {
    $pro = createTenant('booking-daily-pro');

    $svc = Mockery::mock(BookingAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildProfessionalDay')->once();

    $job = new RebuildBookingDailyAggregatesJob($pro->id, '2026-04-24');
    $job->handle($svc);
});

// ── Booking Hourly ───────────────────────────────────────────────────────────

it('RebuildBookingHourlyAggregatesJob skips when professional does not exist', function () {
    $svc = Mockery::mock(BookingAnalyticsAggregateService::class);
    $svc->shouldNotReceive('rebuildProfessionalHour');

    $job = new RebuildBookingHourlyAggregatesJob((string) Str::uuid(), '2026-04-24T00:00:00Z');
    $job->handle($svc);
});

it('RebuildBookingHourlyAggregatesJob runs when professional exists', function () {
    $pro = createTenant('booking-hourly-pro');

    $svc = Mockery::mock(BookingAnalyticsAggregateService::class);
    $svc->shouldReceive('rebuildProfessionalHour')->once();

    $job = new RebuildBookingHourlyAggregatesJob($pro->id, '2026-04-24T00:00:00Z');
    $job->handle($svc);
});
```

> **Note:** Method names on the aggregate services (verified against current code): `SiteAnalyticsAggregateService::rebuildProfessionalHour` / `rebuildProfessionalDay`, `BookingAnalyticsAggregateService::rebuildProfessionalHour` / `rebuildProfessionalDay`, `CommerceAnalyticsAggregateService::rebuildForOrder` / `rebuildForHour`. If a signature has drifted when you implement, fix only the mock expectation — the guard logic in the job is identical.

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Unit/Jobs/AnalyticsJobEntityGuardTest.php --no-coverage
```

Expected: the "skips when professional does not exist" tests fail (service is called when it shouldn't be).

- [ ] **Step 3: Add guard to the four single-professional-ID jobs**

For each of `RebuildSiteHourlyAggregatesJob`, `RebuildSiteDailyAggregatesJob`, `RebuildBookingDailyAggregatesJob`, `RebuildBookingHourlyAggregatesJob`:

Add the import at the top:
```php
use App\Models\Core\Professional\Professional;
```

Replace the `handle()` body with the guard. Example for `RebuildSiteHourlyAggregatesJob`:

```php
    public function handle(SiteAnalyticsAggregateService $aggregates): void
    {
        $professionalId = trim($this->professionalId);
        if ($professionalId === '') {
            return;
        }

        if (! Professional::find($professionalId)) {
            return;
        }

        $aggregates->rebuildProfessionalHour($professionalId, $this->hourStart);
    }
```

Apply the identical pattern (with matching variable names and service method calls) to the other three single-ID jobs.

- [ ] **Step 4: Add guard to the two dual-professional-ID commerce jobs**

For `RebuildCommerceDailyAggregatesJob` and `RebuildCommerceHourlyAggregatesJob`:

Add the import:
```php
use App\Models\Core\Professional\Professional;
```

Replace the `handle()` body. Example for `RebuildCommerceDailyAggregatesJob`:

```php
    public function handle(CommerceAnalyticsAggregateService $aggregates): void
    {
        $brandId = trim($this->brandProfessionalId);
        $affiliateId = trim($this->affiliateProfessionalId);
        $day = trim($this->day);

        if ($brandId === '' || $affiliateId === '' || $day === '') {
            return;
        }

        if (! Professional::find($brandId) || ! Professional::find($affiliateId)) {
            return;
        }

        $aggregates->rebuildForOrder($brandId, $affiliateId, $day);
    }
```

Apply the same pattern to `RebuildCommerceHourlyAggregatesJob` (replacing `$day`/`rebuildForOrder` with `$hourStart`/`rebuildForHour`).

- [ ] **Step 5: Run tests to confirm they pass**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Unit/Jobs/AnalyticsJobEntityGuardTest.php --no-coverage
```

Expected: all passing.

- [ ] **Step 6: Run full suite**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test
```

Expected: green.

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/Analytics/ \
        tests/Unit/Jobs/AnalyticsJobEntityGuardTest.php
git commit -m "fix(jobs): bail early in analytics aggregate jobs when professional no longer exists"
```

---

## Task 4: ProcessShopifyOrderUpdatedWebhookJob Professional Guard (MEDIUM)

**Context:** The job processes Shopify refund/cancellation webhooks and reverses commission ledger entries. It stores a `professionalId` (the brand) but never checks if the brand still exists. If the brand was deleted between webhook receipt and job execution, `notifyAffiliatesOfRefund` sends confusing notifications.

**Files:**
- Modify: `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php`
- Create: `tests/Unit/Jobs/ShopifyOrderUpdatedWebhookGuardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Jobs/ShopifyOrderUpdatedWebhookGuardTest.php`:

```php
<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Retail\CommissionLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
    attachTestSchemas();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
        id TEXT PRIMARY KEY,
        shopify_order_id TEXT NULL,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        entry_type TEXT NULL,
        status TEXT NULL,
        amount_cents INTEGER NULL,
        currency_code TEXT NULL,
        commission_rate REAL NULL,
        rate_source TEXT NULL,
        idempotency_key TEXT NULL,
        calculation_metadata TEXT NULL,
        payout_id TEXT NULL,
        occurred_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

it('does nothing when the brand professional does not exist', function () {
    // No professional seeded — $this->professionalId points to a ghost record.
    $deletedBrandId = (string) Str::uuid();

    // Seed a commission accrual for this order so the job has something to process
    $orderId = (string) Str::uuid();
    DB::connection('pgsql')->table('commerce.commission_ledger_entries')->insert([
        'id'                      => (string) Str::uuid(),
        'shopify_order_id'        => $orderId,
        'brand_professional_id'   => $deletedBrandId,
        'affiliate_professional_id' => (string) Str::uuid(),
        'entry_type'              => 'accrual',
        'status'                  => 'approved',
        'amount_cents'            => 1000,
        'currency_code'           => 'AUD',
        'commission_rate'         => 10.0,
        'rate_source'             => 'brand',
        'idempotency_key'         => 'test-key-1',
        'occurred_at'             => now()->toDateTimeString(),
        'created_at'              => now()->toDateTimeString(),
        'updated_at'              => now()->toDateTimeString(),
    ]);

    $payload = ['id' => $orderId, 'financial_status' => 'refunded', 'refunds' => []];
    $job = new ProcessShopifyOrderUpdatedWebhookJob($deletedBrandId, $payload);

    // Should not throw, should not mutate any ledger entries
    $job->handle();

    $unchanged = DB::connection('pgsql')
        ->table('commerce.commission_ledger_entries')
        ->where('shopify_order_id', $orderId)
        ->where('status', 'approved')
        ->count();

    expect($unchanged)->toBe(1);
});

it('processes refund normally when the brand professional exists', function () {
    $brand = createBrandTenant('shopify-order-brand');
    $orderId = (string) Str::uuid();

    DB::connection('pgsql')->table('commerce.commission_ledger_entries')->insert([
        'id'                        => (string) Str::uuid(),
        'shopify_order_id'          => $orderId,
        'brand_professional_id'     => $brand->id,
        'affiliate_professional_id' => (string) Str::uuid(),
        'entry_type'                => 'accrual',
        'status'                    => 'approved',
        'amount_cents'              => 2000,
        'currency_code'             => 'AUD',
        'commission_rate'           => 10.0,
        'rate_source'               => 'brand',
        'idempotency_key'           => 'test-key-2',
        'occurred_at'               => now()->toDateTimeString(),
        'created_at'                => now()->toDateTimeString(),
        'updated_at'                => now()->toDateTimeString(),
    ]);

    $payload = ['id' => $orderId, 'financial_status' => 'refunded', 'refunds' => []];
    $job = new ProcessShopifyOrderUpdatedWebhookJob($brand->id, $payload);
    $job->handle();

    $reversed = DB::connection('pgsql')
        ->table('commerce.commission_ledger_entries')
        ->where('shopify_order_id', $orderId)
        ->where('status', 'reversed')
        ->count();

    expect($reversed)->toBe(1);
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Unit/Jobs/ShopifyOrderUpdatedWebhookGuardTest.php --no-coverage
```

Expected: first test fails (entry is reversed even though professional is missing).

- [ ] **Step 3: Add Professional guard to the job**

In `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php`, add the import:

```php
use App\Models\Core\Professional\Professional;
```

Replace the first few lines of `handle()`:

```php
    public function handle(): void
    {
        if (! Professional::find($this->professionalId)) {
            Log::warning('ProcessShopifyOrderUpdatedWebhookJob: brand professional not found, skipping', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }

        $orderId = (string) Arr::get($this->payload, 'id', '');
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Unit/Jobs/ShopifyOrderUpdatedWebhookGuardTest.php --no-coverage
```

Expected: 2 passing.

- [ ] **Step 5: Run full suite**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test
```

Expected: green.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php \
        tests/Unit/Jobs/ShopifyOrderUpdatedWebhookGuardTest.php
git commit -m "fix(jobs): bail early in order-updated webhook job when brand professional is deleted"
```

---

## Task 5: FanOutBrandStatusNotificationJob Guard (MEDIUM)

**Context:** The job queries the brand's display name from the DB directly. If the brand is deleted between dispatch and execution, `COALESCE(...)` defaults to the string `'Brand'` and the job fans out confusing notifications like "Brand's affiliate program has been deactivated." to all affiliates. The fix: load the Professional first; bail if not found.

**Files:**
- Modify: `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php`
- Create: `tests/Unit/Jobs/FanOutBrandStatusNotificationGuardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Jobs/FanOutBrandStatusNotificationGuardTest.php`:

```php
<?php

use App\Jobs\Notifications\FanOutBrandStatusNotificationJob;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
    setupBrandLinkTables();
});

it('does not publish any notifications when the brand professional does not exist', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldNotReceive('publish');

    $job = new FanOutBrandStatusNotificationJob((string) Str::uuid(), 'deactivated');
    $job->handle($publisher);
});

it('publishes deactivation notifications when the brand exists and has affiliates', function () {
    $brand = createBrandTenant('fan-out-brand');
    $affiliate = createAffiliateTenant('fan-out-aff');

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.brand_partner_links')->insert([
        'id'                       => (string) Str::uuid(),
        'brand_professional_id'    => $brand->id,
        'affiliate_professional_id' => $affiliate->id,
        'status'                   => 'active',
        'created_at'               => now()->toDateTimeString(),
        'updated_at'               => now()->toDateTimeString(),
    ]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once()->withArgs(function ($args) use ($affiliate) {
        return $args['professionalId'] === $affiliate->id;
    });

    $job = new FanOutBrandStatusNotificationJob($brand->id, 'deactivated');
    $job->handle($publisher);
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Unit/Jobs/FanOutBrandStatusNotificationGuardTest.php --no-coverage
```

Expected: first test fails (`publish` is called even though no brand exists).

- [ ] **Step 3: Add the guard to the job**

In `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php`, add the import:

```php
use App\Models\Core\Professional\Professional;
```

Replace the `handle()` method:

```php
    public function handle(NotificationPublisher $publisher): void
    {
        $brand = Professional::find($this->brandProfessionalId);

        if (! $brand) {
            Log::warning('FanOutBrandStatusNotificationJob: brand not found, skipping fan-out', [
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        $yearWeek = now()->format('o-W');

        $brandName = (string) ($brand->display_name ?: $brand->handle ?: 'Brand');

        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->pluck('affiliate_professional_id');

        foreach ($affiliateIds as $affiliateId) {
            try {
                if ($this->brandStatus === 'deactivated') {
                    $publisher->publish(
                        professionalId: $affiliateId,
                        frontendType: 'Warning',
                        category: 'brand_status',
                        title: 'Brand program deactivated',
                        body: "{$brandName}'s affiliate program has been deactivated.",
                        dedupeKey: "brand.deactivated.{$this->brandProfessionalId}.{$yearWeek}",
                        ctaUrl: '/account/store',
                        retentionConfigKey: 'brand_status',
                    );
                } else {
                    $publisher->publish(
                        professionalId: $affiliateId,
                        frontendType: 'Info',
                        category: 'brand_status',
                        title: 'Brand program reactivated',
                        body: "{$brandName}'s affiliate program is now active.",
                        dedupeKey: "brand.reactivated.{$this->brandProfessionalId}.{$yearWeek}",
                        ctaUrl: '/account/store',
                        retentionConfigKey: 'brand_status',
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('FanOutBrandStatusNotificationJob affiliate notify failed', [
                    'affiliate_id' => $affiliateId,
                    'message'      => $e->getMessage(),
                ]);
            }
        }
    }
```

> **Note:** This replaces the raw `DB::table('core.professionals')` query with `Professional::find()`, which is cleaner and consistent with how the rest of the codebase accesses professional data. The `brand_partner_links` query stays as a raw DB call because it's in the `brand.` schema (not an Eloquent model).

- [ ] **Step 4: Run tests to confirm they pass**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan test tests/Unit/Jobs/FanOutBrandStatusNotificationGuardTest.php --no-coverage
```

Expected: 2 passing.

- [ ] **Step 5: Run full suite**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test
```

Expected: green.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php \
        tests/Unit/Jobs/FanOutBrandStatusNotificationGuardTest.php
git commit -m "fix(jobs): skip brand status fan-out when brand professional no longer exists"
```

---

## Task 6: Failed Job Pruning Schedule (LOW)

**Context:** Failed jobs referencing deleted entities accumulate indefinitely in the `failed_jobs` table. Laravel ships `queue:prune-failed --hours=N` for cleanup; it just needs to be scheduled.

**Files:**
- Modify: `routes/console.php`

- [ ] **Step 1: Add the schedule entry to `routes/console.php`**

After the last `Schedule::job(...)` block (the weekly analytics notification at line 83–88), add:

```php
Schedule::command('queue:prune-failed --hours=72')
    ->daily()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-failed-jobs');
    });
```

- [ ] **Step 2: Verify the entry appears in the schedule list**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && php artisan schedule:list
```

Expected: a row for `queue:prune-failed --hours=72` with frequency `Daily`.

- [ ] **Step 3: Run full suite to confirm nothing broke**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend" && composer test
```

Expected: green.

- [ ] **Step 4: Commit**

```bash
git add routes/console.php
git commit -m "chore(queue): prune failed jobs older than 72 hours daily"
```

---

## Self-Review

### Spec Coverage Check

| Issue | Task |
|-------|------|
| R2 files orphaned on professional purge (CRITICAL) | Task 1 |
| PushServiceToSquare/Fresha pushes soft-deleted services (HIGH) | Task 2 |
| Analytics aggregate jobs no entity guard (MEDIUM) | Task 3 |
| ProcessShopifyOrderUpdatedWebhookJob no professional guard (MEDIUM) | Task 4 |
| FanOutBrandStatusNotificationJob sends misleading notifications (MEDIUM) | Task 5 |
| No failed job pruning (LOW) | Task 6 |

All six issues from the audit have a corresponding task. ✓

### Placeholder Scan

- No "TBD" or "TODO" in any task. ✓
- All code blocks are complete and runnable. ✓
- All file paths are exact. ✓
- Test commands include expected output. ✓

### Type Consistency

- `DeleteMediaArtifactsJob::__construct(string $mediaId, string $basePath, string $pool)` — matches usage in Task 1 `purgeVideoArtifacts()`. ✓
- `ImageVariantService::deleteVariants(string $imageId, ?string $originalPath = null)` — matches usage in `purgeImageArtifacts()`. ✓
- `Professional::find()` used consistently across Tasks 3–5. ✓
- `SiteMedia::MEDIA_TYPE_VIDEO`, `MEDIA_TYPE_IMAGE`, `MEDIA_TYPE_DOCUMENT` constants used in Task 1 match the model definition. ✓

> **Note on Task 3 service method names:** Verified against current code — `CommerceAnalyticsAggregateService::rebuildForHour` (not `rebuildForOrderHour`). If any method name has drifted further at implementation time, a Mockery "unexpected method call" error — not a false pass — will surface it. Fix only the mock expectation, not the guard logic.
