# Image variant pipeline — memory-bound design

**Status:** Approved, pending implementation
**Author:** Josh Hunter (design session with Claude)
**Date:** 2026-04-15
**Branch:** development-v2

---

## Plain-English summary

### What's broken

Right now, every time someone uploads an image to Partna (gallery photo, product photo, brand logo — anything), the server tries to make two copies of it: one medium-quality "optimized" copy and one high-quality "maximized" copy. These two copies are what actually get shown on storefronts and the public site. The original is also saved separately as a backup.

The problem is that both copies are currently made **at the same resolution as the original**. So if you upload a 24-megapixel photo from a modern phone, the server has to hold the original (~96 MB in raw form) *and* a second full-resolution copy (~96 MB) in memory at the same time. That's ~200 MB of RAM for one image. Background worker processes usually only have 256 MB to play with, so anything larger than a typical phone photo can crash the worker.

When a worker crashes, the image gets stuck in a "failed" state, the user sees a broken upload, and you get a Nightwatch alert. For flagship 48 MP phones it crashes more aggressively; for a maliciously crafted "image bomb" it could crash on every upload.

### What we're changing

**Four things, in one pull request:**

1. **Shrink the copies.** The "optimized" copy will be capped at 2400 pixels on the longest edge (plenty for any website rendering), and the "maximized" copy will be capped at 4000 pixels (plenty for any retina display). Anything smaller than those caps is left alone — no upscaling. The original is still saved at full resolution, untouched, so nothing is lost.

2. **Refuse absurdly large uploads.** Before the server even tries to decode an image, it peeks at the file header to check how big it is. If it's bigger than 24 megapixels (bigger than any modern phone except flagship 48 MP sensors), the server refuses it with a clean error message instead of crashing mid-processing. The limit is tunable via an environment variable, so we can bump it up once we verify workers have the memory for it.

3. **Don't retry impossible work.** If an image is rejected for being too big, the background job currently retries it 3 times (each ~30 seconds apart) before giving up — wasteful, because the image isn't going to magically shrink between retries. We'll teach the job to recognize "this is a permanent failure, stop trying" and fail immediately instead.

4. **Delete dead code.** Two methods (`downloadRemoteImage` and `storeOriginalBytes`) that were added two days ago for the Shopify brand logo sync are now orphaned — Tobias's refactor yesterday removed the only code that called them. We're deleting them now, before anyone accidentally starts depending on them again.

### What the user will notice

- **Nothing, for normal uploads.** Phone photos, typical gallery shots, brand logos — these all continue to work the same way, but faster and without ever crashing a worker.
- **Faster page loads.** Storefront hero images drop from ~4-8 MB to under 1 MB with no visible quality change. Mobile network users see significant improvements.
- **Lower R2 storage bills.** Every uploaded image currently stores its "maximized" variant at nearly the same size as the original. After this change, variants are bounded and typically much smaller.
- **Clean error for absurd uploads.** If someone tries to upload a 100 MP image, they get "Image dimensions exceed safe processing limit" in the gallery instead of a stuck-forever processing spinner.

### Why this is safe

- **The originals are untouched.** `storeOriginal()` saves the raw uploaded file to R2 before any variant processing happens. We're only bounding the *derived* copies, not the source archive.
- **Re-processing is always an option.** If we ever want to change variant strategy, raise the caps, or add a new variant format, we re-run the pipeline against the stored originals. No data loss.
- **No customer impact.** Partna is pre-beta with no live customers. The fix can be shipped and observed before any real user hits it.

---

## Technical summary

### Problem statement

`ImageVariantService::processVariants()` (`app/Services/Media/ImageVariantService.php:37`) currently allocates a full-source-resolution canvas for every variant because both default variants set `preserve_resolution => true` in `config/sidest.php:273,280`. Combined with the unavoidable full-resolution source bitmap from `imagecreatefromjpeg()` (line 338), peak memory is `8 × source_pixels` per variant iteration. A 24 MP source requires ~192 MB; a 48 MP source requires ~384 MB. On workers with a 256 MB `memory_limit`, both scenarios produce a PHP fatal error at `imagecreatetruecolor()` (line 97) or at the initial `imagecreatefromjpeg()` decode.

The failure manifests as:
- Horizon worker process death mid-job
- Horizon's failed-job handler marks the job failed after 3 retries
- `SiteMedia.processing_state` transitions to `failed` with no useful error message
- User sees a broken upload in their gallery

Frequency scales with user device: phones with 12-16 MP sensors are safe; 24-48 MP flagship sensors are vulnerable. Image-bomb attacks (tiny file, huge decoded resolution) are also possible via any upload endpoint that accepts user-provided images.

### Root cause

Three tightly-coupled contributors:

1. **`preserve_resolution => true` on both default variants** — introduced in commit `37b4749` (2026-03-11), uses the `if ($preserveResolution)` branch at `ImageVariantService.php:85-86` which spreads `[$sourceWidth, $sourceHeight]` into the canvas dimensions, matching the source exactly.
2. **`$def['preserve_resolution'] ?? true` default at line 79** — if a future variant definition omits the flag, the unsafe behavior is the default.
3. **No pixel-count gate before decode** — `loadImage()` at line 330 calls `getimagesize()` for type dispatch but discards `$info[0]`/`$info[1]`, so there's nothing stopping an arbitrarily large image from reaching `imagecreatefromjpeg()`.

### Scope

**In scope:**
- Replace both default variant definitions with explicit pixel caps
- Flip the unsafe default at `ImageVariantService.php:79` from `?? true` to `?? false`
- Add a pixel-count guard in `loadImage()` using the `getimagesize()` call already happening there
- Introduce `UnprocessableImageException` and make `ProcessImageVariantsJob` skip retries on it
- Delete the orphaned `downloadRemoteImage()` and `storeOriginalBytes()` methods
- Net-new test coverage for `ImageVariantService` and `ProcessImageVariantsJob`

**Out of scope (explicitly deferred):**
- Pixel-dimension validation in `UploadImageRequest` and other FormRequests (Layer 3 from brainstorming — front-of-house UX) — good idea, too much surface area for this PR, can be a follow-up.
- Replacing GD with `intervention/image` — separate, larger refactor that would also solve EXIF orientation bugs.
- Re-processing existing uploaded images under the new caps. Pre-beta, no need.
- Database or migration changes. None required.
- Changes to any upload controller. None required.

### Decisions log (from brainstorming session)

| Question | Decision | Reason |
|---|---|---|
| Why does `preserve_resolution => true` exist? | Unknown / inertial | Josh: "I don't know why we had it, is it needed" → investigation concluded it was unnecessary given `storeOriginal()` already archives the source |
| Should we drop `preserve_resolution` entirely? | Yes | Web-only delivery, no print pipeline, originals are already preserved separately — full-resolution variants are redundant |
| `optimized` cap? | 2400×2400 long edge, 500 KB target | Covers all realistic in-page rendering on web and mobile |
| `maximized` cap? | 4000×4000 long edge, quality 92 | Covers hero images and 3× retina hi-DPI, quality 92 is visually indistinguishable from 100 in WebP |
| Megapixel guard ceiling? | 24 MP default, env-overridable via `SIDEST_IMAGE_MAX_PIXELS` | Conservative default that works on 256 MB workers; tunable up to 50 MP once worker memory is verified |
| Dead code (`downloadRemoteImage`, `storeOriginalBytes`)? | Delete | Zero callers since commit `62d3c54` removed `SyncShopifyBrandLogoJob` |
| FormRequest-layer validation (Layer 3)? | Defer | Adds surface area across multiple FormRequest classes for a UX nicety, not the load-bearing safety boundary |
| Skip retries on unprocessable images? | Yes, via new `UnprocessableImageException` class | Retrying a guard rejection is pure waste; worth the ~20 LOC for correct behavior |

---

## Files changed

| File | Change type | Description |
|---|---|---|
| `config/sidest.php` | Modified | Replace both `image_variants` definitions (lines 270-283) with explicit width/height/fit. Add new `image_max_pixels` config key. |
| `app/Services/Media/ImageVariantService.php` | Modified | (1) Add pixel-count guard in `loadImage()` reading from existing `getimagesize()` call. (2) Flip `?? true` → `?? false` at line 79. (3) Update inline `variantDefinitions()` fallback (lines 303-325) to mirror new config shape. (4) Delete `downloadRemoteImage()` and `storeOriginalBytes()` methods (lines 211-293). (5) Remove `use Illuminate\Support\Facades\Http;` import at line 7 (only used by deleted code). |
| `app/Services/Media/UnprocessableImageException.php` | New | Marker exception class extending `\RuntimeException`. Signals to the job layer that retries should be skipped. |
| `app/Jobs/ProcessImageVariantsJob.php` | Modified | Add `catch (UnprocessableImageException $e)` branch in `handle()` that calls `markFailed()` + `fail()` + `return` (no rethrow). Keep existing generic `Throwable` catch for transient failures. |
| `tests/Feature/Media/ImageVariantServiceTest.php` | New | Feature test covering variant generation, pixel caps, guard behavior, aspect preservation, no-upscale, env override, quality targeting. 7 test cases. |
| `tests/Feature/Jobs/ProcessImageVariantsJobTest.php` | New | Feature test covering happy path, retry-skipping on `UnprocessableImageException`, retry-on-generic-failure, and processing_error population. 4 test cases. |

### Concrete config change

```php
// config/sidest.php — replace lines 270-283
'image_variants' => [
    'optimized' => [
        'format'     => 'webp',
        'width'      => 2400,
        'height'     => 2400,
        'fit'        => 'inside',
        'quality'    => (int) env('SIDEST_IMAGE_QUALITY', 92),
        'min_quality' => (int) env('SIDEST_IMAGE_MIN_QUALITY', 60),
        'target_kb'  => (int) env('SIDEST_IMAGE_TARGET_KB', 500),
    ],
    'maximized' => [
        'format'  => 'webp',
        'width'   => 4000,
        'height'  => 4000,
        'fit'     => 'inside',
        'quality' => (int) env('SIDEST_IMAGE_MAXIMIZED_QUALITY', 92),
    ],
],

// Add alongside image_max_upload_size (line 285)
'image_max_pixels' => (int) env('SIDEST_IMAGE_MAX_PIXELS', 24_000_000), // 24 MP
```

### Concrete `loadImage()` change

```php
// app/Services/Media/ImageVariantService.php, replacing lines 330-343
private function loadImage(string $path): \GdImage|false
{
    $info = @getimagesize($path);
    if (!$info) {
        return false;
    }

    $width  = (int) $info[0];
    $height = (int) $info[1];
    $maxPixels = (int) config('sidest.image_max_pixels', 24_000_000);

    if ($width * $height > $maxPixels) {
        throw new UnprocessableImageException(sprintf(
            'Image dimensions exceed safe processing limit (%d x %d = %d pixels, max %d).',
            $width,
            $height,
            $width * $height,
            $maxPixels,
        ));
    }

    return match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default        => false,
    };
}
```

### Concrete default flip

```php
// app/Services/Media/ImageVariantService.php:79
// Before:
$preserveResolution = filter_var(
    $def['preserve_resolution'] ?? true,
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE,
);
$preserveResolution = $preserveResolution ?? true;

// After:
$preserveResolution = filter_var(
    $def['preserve_resolution'] ?? false,
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE,
);
$preserveResolution = $preserveResolution ?? false;
```

Note: `preserve_resolution` support is retained for backward compatibility (a future variant definition could still opt in), but it's no longer the unsafe default.

### Concrete job catch branch

```php
// app/Jobs/ProcessImageVariantsJob.php — replace the existing catch (Throwable $e) block
        } catch (UnprocessableImageException $e) {
            Log::warning('ProcessImageVariantsJob: unprocessable image, failing without retry.', [
                'image_id' => $this->imageId,
                'error'    => $e->getMessage(),
            ]);

            $this->markFailed($e->getMessage());
            $this->fail($e);

            return;
        } catch (Throwable $e) {
            Log::error('ProcessImageVariantsJob: variant generation failed.', [
                'image_id'  => $this->imageId,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw $e;
        } finally {
```

Requires adding `use App\Services\Media\UnprocessableImageException;` to the file's imports.

### Concrete inline fallback update

The `variantDefinitions()` fallback at `ImageVariantService.php:303-325` must mirror the new config shape, or tests that load a sparse config will silently regress:

```php
// app/Services/Media/ImageVariantService.php:311-324 — the fallback block
return [
    'optimized' => [
        'format'      => 'webp',
        'width'       => 2400,
        'height'      => 2400,
        'fit'         => 'inside',
        'quality'     => 92,
        'min_quality' => 60,
        'target_kb'   => 500,
    ],
    'maximized' => [
        'format'  => 'webp',
        'width'   => 4000,
        'height'  => 4000,
        'fit'     => 'inside',
        'quality' => 92,
    ],
];
```

### Dead code deletion

Lines 211-293 of `ImageVariantService.php` (the `downloadRemoteImage()` and `storeOriginalBytes()` methods) are deleted in their entirety. The `use Illuminate\Support\Facades\Http;` import at line 7 is also removed — it's only referenced by `downloadRemoteImage()`.

Verification: `rg 'downloadRemoteImage|storeOriginalBytes'` returns zero matches in the current codebase.

---

## Behavior specification

### Data flow — successful upload (unchanged from status quo)

1. User POSTs to an upload endpoint with an image file.
2. Controller's FormRequest validates file size (≤10 MB) and mime type (jpeg/png/webp).
3. Controller calls `ImageVariantService::storeOriginal()` to archive the source on R2.
4. Controller creates a `SiteMedia` row with `processing_state = 'pending'`.
5. Controller dispatches `ProcessImageVariantsJob` to the `images` queue.
6. Horizon worker picks up the job.
7. Job transitions `processing_state` to `processing`, downloads the original to a local temp file, and calls `ImageVariantService::processVariants()`.
8. `processVariants()` calls `loadImage()`, which runs `getimagesize()`, checks the megapixel guard, and decodes the source bitmap.
9. For each variant definition, `processVariants()` computes the capped canvas dimensions via `calculateDimensions()`, allocates the canvas, encodes to WebP (binary-searching for target_kb on `optimized`), uploads to R2, and creates a `MediaVariant` row.
10. Job transitions `processing_state` to `ready`.

### Data flow — image exceeds megapixel guard (new behavior)

1. Steps 1-7 as above.
2. `processVariants()` calls `loadImage()`, which runs `getimagesize()` and computes `width * height > image_max_pixels`.
3. `loadImage()` throws `UnprocessableImageException` with a message naming the actual and max pixel counts.
4. The exception propagates up from `processVariants()` into `ProcessImageVariantsJob::handle()`'s new `catch (UnprocessableImageException $e)` branch.
5. Job logs a warning (not an error — this is an expected outcome, not a bug), calls `markFailed($e->getMessage())` to write the error into `SiteMedia.processing_error`, and calls `$this->fail($e)` to mark the job permanently failed without releasing it back to the queue.
6. Job returns without rethrowing. Retry counter never increments.
7. Horizon shows the job as failed on the first attempt. `SiteMedia.processing_state = 'failed'`, `processing_error = 'Image dimensions exceed safe processing limit...'`.
8. Frontend can surface the error message to the user on next gallery refresh.

### Data flow — transient failure (unchanged)

Any exception that is **not** `UnprocessableImageException` (R2 unavailable, temp-file IO error, GD decode failure on a corrupt file) falls through to the existing generic `catch (Throwable $e)` branch and is rethrown, triggering Laravel's retry machinery (`$tries = 3`, `$backoff = 30`).

### Memory envelope

| Source resolution | Old peak | New peak | Behavior |
|---|---|---|---|
| ≤6 MP | ~48 MB | ~48 MB | Unchanged |
| 12 MP (typical phone) | ~96 MB | ~96 MB | Unchanged for `maximized` (source already fits under 4000px cap), downscaled for `optimized` (17 MB canvas instead of 48 MB) |
| 24 MP | ~192 MB | ~140 MB | 27% reduction |
| 25-50 MP | up to ~400 MB | refused at guard | New error path |
| >50 MP | up to ~768 MB+ | refused at guard | New error path |

The memory reduction is concentrated on 24+ MP uploads, which is exactly the modern-phone problem case.

---

## Testing strategy

### `tests/Feature/Media/ImageVariantServiceTest.php`

| Test name | What it proves |
|---|---|
| `generates optimized and maximized variants for a normal-sized image` | Baseline happy path — service works end-to-end against a fake disk |
| `downscales variants when source exceeds the cap` | Core regression test for the `preserve_resolution` flip — 6000x4000 input yields 2400x1600 optimized and 4000x2666 maximized |
| `preserves aspect ratio for portrait images` | Catches width/height confusion — 3000x4000 portrait yields 1800x2400 optimized |
| `leaves small images untouched when under both caps` | No accidental upscaling — 800x600 stays 800x600 |
| `throws UnprocessableImageException when pixel count exceeds the guard` | Guard regression — 6000x5000 (30 MP) is refused with a typed exception and no variants are created |
| `respects the SIDEST_IMAGE_MAX_PIXELS env override` | Config is actually wired to guard — tighten to 1 MP, assert a 1.44 MP image is refused |
| `generates a variant file under the target_kb for the optimized variant` | Binary-search quality targeting still works with new caps |

### `tests/Feature/Jobs/ProcessImageVariantsJobTest.php`

| Test name | What it proves |
|---|---|
| `marks the SiteMedia row as ready on successful processing` | Happy path — job transitions state correctly |
| `skips retries when processVariants throws UnprocessableImageException` | New catch branch regression — job fails immediately on permanent errors, job is not re-released to the queue |
| `retries on transient failures` | Permanent-failure logic didn't accidentally make ALL failures permanent |
| `marks row as failed and records the error message on permanent failure` | `processing_error` gets a human-readable message, not a stack trace |

### Fixture generation

Test fixtures are generated in-test via GD (`imagecreatetruecolor`, `imagefilledrectangle`, `imagejpeg`) — no binary files committed to the repo. A small helper function at the top of each test file creates a solid-color image of requested dimensions and format.

### What's deliberately not tested

- `calculateDimensions()` as a standalone unit — covered transitively by the variant-generation tests.
- GD standard library behavior (`imagecreatefromjpeg`, `imagewebp`).
- Peak memory consumption — not reliably measurable in a shared-process PHP test runner. Regression protection comes from asserting on canvas dimensions (which drive memory usage), not on `memory_get_peak_usage()`.
- Dead code deletion — if anything secretly used `downloadRemoteImage()` or `storeOriginalBytes()`, the existing test suite would fail on deletion.

---

## Verification commands

```bash
composer test -- --filter=ImageVariantServiceTest
composer test -- --filter=ProcessImageVariantsJobTest
composer test                                        # full suite must stay green
php artisan pint                                     # code style
```

After merge, monitor Nightwatch for:
- Reduction in `ProcessImageVariantsJob` failure rate
- Absence of PHP fatal errors mentioning `imagecreatetruecolor` or `imagecreatefromjpeg`
- Any new occurrences of `UnprocessableImageException` (expected: rare, benign)

---

## Rollback plan

Revert the single PR. No database migrations, no destructive actions, no data loss on rollback. Previously-uploaded images remain unchanged (they were processed under the old behavior and their variant rows are untouched by this change).

If a specific deployment shows unexpected behavior:
- Raise `SIDEST_IMAGE_MAX_PIXELS` via env to loosen the guard without a code deploy
- Raise `SIDEST_IMAGE_QUALITY` / `SIDEST_IMAGE_MAXIMIZED_QUALITY` via env if quality regressions are reported

---

## Open questions / follow-ups

- **FormRequest-layer validation (Layer 3).** Deferred from this PR. Worth scheduling as a separate small task — adds immediate-feedback UX by rejecting oversized uploads at the HTTP boundary instead of in the background job.
- **Intervention Image migration.** Also deferred. Worth a separate brainstorming session if / when EXIF orientation bugs become a reported issue, or if we outgrow GD for any other reason.
- **Worker memory calibration.** The 24 MP default is conservative for a 256 MB worker. Once Laravel Cloud worker memory is verified, consider raising `SIDEST_IMAGE_MAX_PIXELS` to 50 MP (supports 48 MP flagship phones).
- **`preserve_resolution` flag itself.** Left in place as an opt-in escape hatch but no longer the default. If it turns out nothing ever sets it to `true` again, it can be removed entirely in a follow-up cleanup.
