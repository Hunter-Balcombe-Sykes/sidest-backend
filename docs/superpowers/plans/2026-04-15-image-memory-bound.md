# Image variant pipeline — memory-bound implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **For Josh:** Each task ends with a commit step. Commit messages are pre-written; either run them yourself or dispatch a subagent. Commits happen at task boundaries only — no intermediate broken states.

**Goal:** Fix the image-processing memory-crash class by dropping `preserve_resolution => true`, bounding variant canvas sizes, adding a megapixel guard in `loadImage()`, making the processing job skip retries on permanent validation failures, and deleting orphaned Shopify-logo methods.

**Architecture:** Surgical edit. Single service (`ImageVariantService`), single job (`ProcessImageVariantsJob`), single config (`sidest.php`). One new file (`UnprocessableImageException`). Two new test files. No controllers touched, no migrations, no database changes. The existing `calculateDimensions()` + `'inside'` fit path is already implemented and reachable — we just make the config actually use it.

**Tech Stack:** PHP 8.2, Laravel 12, GD extension (WebP), Pest 4, Mockery, SQLite in-memory test DB with attached schemas.

**Spec:** `docs/superpowers/specs/2026-04-15-image-memory-bound-design.md`

**Branch:** Work on `development-v2` directly or in a worktree — no strong preference. No migrations to coordinate.

---

## File structure

| File | Responsibility | Action |
|---|---|---|
| `app/Services/Media/UnprocessableImageException.php` | Marker exception class. Tells the job layer a failure is permanent. | Create |
| `config/sidest.php` | Variant definitions + megapixel ceiling. | Modify |
| `app/Services/Media/ImageVariantService.php` | Bounded canvas, megapixel guard, inline fallback mirrors config, dead code deleted. | Modify |
| `app/Jobs/ProcessImageVariantsJob.php` | Catches `UnprocessableImageException` and skips retry. | Modify |
| `tests/Feature/Media/ImageVariantServiceTest.php` | Service-level variant generation + guard coverage. | Create |
| `tests/Feature/Jobs/ProcessImageVariantsJobTest.php` | Job-level retry-skip + happy-path coverage. | Create |

**Commit boundaries:** One commit per task. Each task leaves the codebase in a shippable state.

---

## Task 1: Create `UnprocessableImageException`

**Files:**
- Create: `app/Services/Media/UnprocessableImageException.php`

**Why first:** The exception class is a dependency for Tasks 3 and 5. Isolating it into its own commit makes the diff easy to review — just a marker class with zero logic, zero risk, zero tests required.

- [ ] **Step 1: Create the exception class file**

Create `app/Services/Media/UnprocessableImageException.php`:

```php
<?php

namespace App\Services\Media;

/**
 * Thrown when an image cannot be processed due to a permanent, non-retryable
 * condition (e.g. pixel dimensions exceed the safe decode ceiling). The
 * ProcessImageVariantsJob recognises this class and skips the retry path,
 * marking the SiteMedia row as failed on the first attempt.
 */
class UnprocessableImageException extends \RuntimeException
{
}
```

- [ ] **Step 2: Regenerate autoload and run the full test suite**

Run: `composer dump-autoload && composer test`
Expected: All existing tests pass. New class loads without error.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Media/UnprocessableImageException.php
git commit -m "$(cat <<'EOF'
feat(media): add UnprocessableImageException marker class

Thin exception class signalling that an image cannot be processed due
to a permanent, non-retryable condition. Consumed by
ProcessImageVariantsJob in a follow-up commit to skip the retry path
for guard-rejected images.

No tests: the class has zero logic. Transitive test coverage comes from
the service tests that throw it and the job tests that catch it.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Bound variant canvas dimensions

**Files:**
- Create: `tests/Feature/Media/ImageVariantServiceTest.php`
- Modify: `config/sidest.php` (lines 270–283)
- Modify: `app/Services/Media/ImageVariantService.php` (lines 78–83, 311–324)

**Why this task:** This is the load-bearing fix. Replaces `preserve_resolution => true` on both default variants with explicit `width`/`height`/`fit`, flips the `?? true` default to `?? false` so omission is safe, and updates the inline `variantDefinitions()` fallback to match. Writes 5 tests covering variant generation, the downscaling math, aspect preservation, no-upscale, and target_kb behavior. Uses existing `setupMediaTables()` + local test disk pattern from `MediaJobReliabilityTest.php`.

- [ ] **Step 1: Write the failing test file**

Create `tests/Feature/Media/ImageVariantServiceTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();

    // Use a real local disk rooted in a unique test directory, mirroring the
    // pattern in tests/Unit/MediaJobReliabilityTest.php. Keeps filesystem state
    // inspectable and avoids Storage::fake() quirks around the media disk.
    $testRoot = storage_path('framework/testing/disks/image-variant-service');
    config([
        'sidest.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
    ]);

    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    } else {
        // Clean between tests so content-hashed paths don't accumulate.
        foreach (glob($testRoot . '/**/*', GLOB_BRACE) as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
});

/**
 * Create a solid-color JPEG/PNG/WebP fixture at the requested dimensions.
 * Returns the absolute path to the temp file.
 */
function makeVariantFixture(int $width, int $height, string $format = 'jpeg'): string
{
    $img = imagecreatetruecolor($width, $height);
    $bg = imagecolorallocate($img, 200, 150, 100);
    imagefilledrectangle($img, 0, 0, $width, $height, $bg);

    $path = tempnam(sys_get_temp_dir(), 'sidest_variant_test_');
    match ($format) {
        'jpeg' => imagejpeg($img, $path, 90),
        'png'  => imagepng($img, $path),
        'webp' => imagewebp($img, $path, 90),
    };
    imagedestroy($img);

    return $path;
}

function seedVariantTestMediaRow(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => '',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_PROCESSING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    return $id;
}

it('generates optimized and maximized variants for a normal-sized image', function () {
    $service = new ImageVariantService();
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(1000, 1000, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    expect($variants)->toHaveKeys(['optimized', 'maximized']);
    expect($variants['optimized']->path)->toStartWith("images/test/{$imageId}/optimized_");
    expect($variants['maximized']->path)->toStartWith("images/test/{$imageId}/maximized_");
    expect(Storage::disk('local')->exists($variants['optimized']->path))->toBeTrue();
    expect(Storage::disk('local')->exists($variants['maximized']->path))->toBeTrue();

    @unlink($fixture);
});

it('downscales variants when source exceeds the cap', function () {
    $service = new ImageVariantService();
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(6000, 4000, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    // optimized cap = 2400 long edge. 6000x4000 → ratio 0.4 → 2400x1600.
    expect($variants['optimized']->width)->toBe(2400);
    expect($variants['optimized']->height)->toBe(1600);

    // maximized cap = 4000 long edge. 6000x4000 → ratio 0.667 → 4000x2666 (rounded).
    expect($variants['maximized']->width)->toBe(4000);
    expect($variants['maximized']->height)->toBeGreaterThanOrEqual(2666);
    expect($variants['maximized']->height)->toBeLessThanOrEqual(2667);

    @unlink($fixture);
});

it('preserves aspect ratio for portrait images', function () {
    $service = new ImageVariantService();
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(3000, 4000, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    // optimized: 3000x4000 → ratio min(2400/3000, 2400/4000, 1) = 0.6 → 1800x2400
    expect($variants['optimized']->width)->toBe(1800);
    expect($variants['optimized']->height)->toBe(2400);

    // maximized: 3000x4000 → ratio min(4000/3000, 4000/4000, 1) = 1 → 3000x4000 (unchanged)
    expect($variants['maximized']->width)->toBe(3000);
    expect($variants['maximized']->height)->toBe(4000);

    @unlink($fixture);
});

it('leaves small images untouched when under both caps', function () {
    $service = new ImageVariantService();
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(800, 600, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    // min(2400/800, 2400/600, 1) = 1 → no upscale
    expect($variants['optimized']->width)->toBe(800);
    expect($variants['optimized']->height)->toBe(600);
    expect($variants['maximized']->width)->toBe(800);
    expect($variants['maximized']->height)->toBe(600);

    @unlink($fixture);
});

it('generates an optimized variant file under the target_kb ceiling', function () {
    $service = new ImageVariantService();
    $imageId = seedVariantTestMediaRow();
    $fixture = makeVariantFixture(2000, 2000, 'jpeg');

    $variants = $service->processVariants(
        originalTmpPath: $fixture,
        imageId: $imageId,
        basePath: "images/test/{$imageId}",
    );

    $targetBytes = 500 * 1024;
    // Small solid-colour fixtures encode well under 500KB — this asserts the
    // binary-search quality path stayed wired up, not that targeting is tight.
    expect($variants['optimized']->file_size_bytes)->toBeLessThanOrEqual($targetBytes);

    @unlink($fixture);
});
```

- [ ] **Step 2: Run the failing tests to confirm they fail**

Run: `composer test -- --filter=ImageVariantServiceTest`
Expected: All 5 tests FAIL. Specifically, the `downscales variants when source exceeds the cap` and `preserves aspect ratio for portrait images` tests will fail because the current config still has `preserve_resolution => true`, so `calculateDimensions()` never runs and the canvas is set to source dimensions (6000×4000 and 3000×4000 respectively). The `leaves small images untouched` test might coincidentally pass. The `generates optimized and maximized variants` happy-path test should pass since it only asserts path existence.

Note the failures. They represent the bug we are fixing.

- [ ] **Step 3: Update `config/sidest.php` to drop `preserve_resolution` and add explicit dimensions**

Replace the block at `config/sidest.php:270-283` (locate by searching for `'image_variants' =>`):

Current:
```php
    'image_variants' => [
        'optimized' => [
            'format' => 'webp',
            'preserve_resolution' => true,
            'quality' => (int) env('SIDEST_IMAGE_QUALITY', 92),
            'min_quality' => (int) env('SIDEST_IMAGE_MIN_QUALITY', 60),
            'target_kb' => (int) env('SIDEST_IMAGE_TARGET_KB', 500),
        ],
        'maximized' => [
            'format' => 'webp',
            'preserve_resolution' => true,
            'quality' => (int) env('SIDEST_IMAGE_MAXIMIZED_QUALITY', 100),
        ],
    ],
```

New:
```php
    'image_variants' => [
        'optimized' => [
            'format'      => 'webp',
            'width'       => 2400,
            'height'      => 2400,
            'fit'         => 'inside',
            'quality'     => (int) env('SIDEST_IMAGE_QUALITY', 92),
            'min_quality' => (int) env('SIDEST_IMAGE_MIN_QUALITY', 60),
            'target_kb'   => (int) env('SIDEST_IMAGE_TARGET_KB', 500),
        ],
        'maximized' => [
            'format'  => 'webp',
            'width'   => 4000,
            'height'  => 4000,
            'fit'     => 'inside',
            'quality' => (int) env('SIDEST_IMAGE_MAXIMIZED_QUALITY', 92),
        ],
    ],
```

Two things to notice:
1. `preserve_resolution => true` removed from both variants.
2. `maximized` quality dropped from `env(..., 100)` to `env(..., 92)`. Quality 92 is visually indistinguishable from 100 in WebP and is ~30% smaller.

Also update the comment block above at `config/sidest.php:261-269` to reflect the new shape:

Current (locate by searching for `| - optimized: adaptive quality`):
```php
    /*
    |----------------------------------------------------------------------
    | Image variants configuration
    |----------------------------------------------------------------------
    | - optimized: adaptive quality target (~500KB by default)
    | - maximized: highest quality full-resolution WebP
    |
    | preserve_resolution = keep original dimensions (no resize cap).
    | quality             = preferred WebP quality ceiling (1-100).
    | min_quality         = lowest allowed quality while targeting size.
    | target_kb           = target max file size in kilobytes.
    */
```

New:
```php
    /*
    |----------------------------------------------------------------------
    | Image variants configuration
    |----------------------------------------------------------------------
    | - optimized: adaptive quality targeting ~500KB, capped at 2400px
    |   long edge. Serves in-page rendering and gallery thumbnails.
    | - maximized: higher quality cap at 4000px long edge. Serves hero
    |   images and 3x retina hi-DPI displays.
    |
    | width / height = pixel caps applied via 'inside' fit — never upscales,
    |                  preserves aspect ratio, caps the long edge to the
    |                  smaller of the two dimensions. Equal w/h = long-edge cap.
    | fit            = 'inside' (fit within bounds, no crop) or 'cover' (crop).
    | quality        = preferred WebP quality ceiling (1-100). 92 is visually
    |                  indistinguishable from 100 and ~30% smaller.
    | min_quality    = lowest allowed quality while targeting size.
    | target_kb      = target max file size in kilobytes (triggers binary-search
    |                  quality targeting when set).
    |
    | NOTE: the preserve_resolution flag is still honoured when explicitly set
    | on a variant definition, but is no longer the default. Originals are
    | always stored in full via storeOriginal() — variants are for delivery.
    */
```

- [ ] **Step 4: Flip the `?? true` default to `?? false` in `ImageVariantService`**

Modify `app/Services/Media/ImageVariantService.php` at lines 78-83 (locate by searching for `$preserveResolution = filter_var`):

Current:
```php
                $preserveResolution = filter_var(
                    $def['preserve_resolution'] ?? true,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );
                $preserveResolution = $preserveResolution ?? true;
```

New:
```php
                $preserveResolution = filter_var(
                    $def['preserve_resolution'] ?? false,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );
                $preserveResolution = $preserveResolution ?? false;
```

- [ ] **Step 5: Update the inline `variantDefinitions()` fallback**

Modify `app/Services/Media/ImageVariantService.php` at lines 311-324 (locate by searching for `'optimized' => [` inside `variantDefinitions()`):

Current:
```php
        return [
            'optimized' => [
                'format' => 'webp',
                'preserve_resolution' => true,
                'quality' => 92,
                'min_quality' => 60,
                'target_kb' => 500,
            ],
            'maximized' => [
                'format' => 'webp',
                'preserve_resolution' => true,
                'quality' => 100,
            ],
        ];
```

New:
```php
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

This fallback only runs when `config('sidest.image_variants')` is empty — e.g. in tests that load a sparse config. It MUST mirror the real config or tests can silently regress against prod behavior.

- [ ] **Step 6: Run the failing tests to confirm they now pass**

Run: `composer test -- --filter=ImageVariantServiceTest`
Expected: All 5 tests PASS.

- [ ] **Step 7: Run the full test suite to confirm no regressions**

Run: `composer test`
Expected: Full suite green. Particularly watch for any existing tests that reference `image_variants` config or `ImageVariantService` — e.g. `tests/Unit/MediaJobReliabilityTest.php` and `tests/Feature/MediaUploadFailureHandlingTest.php`. None of them assert on variant dimensions, so they should be unaffected.

- [ ] **Step 8: Commit**

```bash
git add config/sidest.php \
        app/Services/Media/ImageVariantService.php \
        tests/Feature/Media/ImageVariantServiceTest.php
git commit -m "$(cat <<'EOF'
fix(media): bound image variant canvas dimensions

Replaces preserve_resolution=true on both default variants (optimized,
maximized) with explicit width/height/fit, so the existing 'inside' fit
path in calculateDimensions() actually runs. Caps are 2400px long edge
for optimized (in-page / gallery) and 4000px for maximized (hero / hi-DPI).
Quality default on maximized drops from 100 to 92 — visually identical
in WebP, ~30% smaller files.

Originals remain untouched via storeOriginal() — nothing is lost. This
purely bounds the *derived* delivery copies.

Also flips the $def['preserve_resolution'] ?? true default to ?? false
at line 79 so future variant definitions that omit the flag get the safe
behavior, not the unsafe one. preserve_resolution is still honoured when
explicitly set, but is no longer the default.

Updates the inline variantDefinitions() fallback to mirror the new config
shape so sparse test configs don't silently run old behavior.

Adds 5 service-level tests covering happy path, downscale math, portrait
aspect preservation, no-upscale, and target_kb binary-search targeting.

Fixes the memory-crash class that OOMs workers on 24+ MP uploads.
See docs/superpowers/specs/2026-04-15-image-memory-bound-design.md.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Add megapixel guard in `loadImage()`

**Files:**
- Modify: `config/sidest.php` (add `image_max_pixels` key alongside `image_max_upload_size` at line 285)
- Modify: `app/Services/Media/ImageVariantService.php` (lines 330–343 `loadImage()` method)
- Modify: `tests/Feature/Media/ImageVariantServiceTest.php` (append 2 new tests)

**Why this task:** The canvas cap from Task 2 bounds the peak memory for *decoded* images, but nothing bounds the source decode itself. A maliciously crafted image bomb (tiny file, huge resolution) or a 96 MP RAW can still OOM the worker at `imagecreatefromjpeg()`. The guard uses the `getimagesize()` call that already happens inside `loadImage()` (line 332) to check pixel count BEFORE decoding, throwing `UnprocessableImageException` if it exceeds the configured ceiling. Default ceiling is 24 MP (env-overridable) — conservative for 256 MB workers, tunable up.

- [ ] **Step 1: Append the two failing guard tests to `tests/Feature/Media/ImageVariantServiceTest.php`**

Append these tests to the bottom of the existing test file created in Task 2:

```php
it('throws UnprocessableImageException when pixel count exceeds the guard', function () {
    $service = new ImageVariantService();
    $imageId = seedVariantTestMediaRow();
    // 6000 × 5000 = 30 MP, above the 24 MP default ceiling.
    $fixture = makeVariantFixture(6000, 5000, 'jpeg');

    try {
        $service->processVariants(
            originalTmpPath: $fixture,
            imageId: $imageId,
            basePath: "images/test/{$imageId}",
        );
        throw new \RuntimeException('Expected UnprocessableImageException was not thrown');
    } catch (\App\Services\Media\UnprocessableImageException $e) {
        expect($e->getMessage())->toContain('exceed safe processing limit');
        expect($e->getMessage())->toContain('6000');
        expect($e->getMessage())->toContain('5000');
    } finally {
        @unlink($fixture);
    }

    // No MediaVariant rows should have been created.
    $variantCount = DB::connection('pgsql')
        ->table('site.media_variants')
        ->where('media_id', $imageId)
        ->count();
    expect($variantCount)->toBe(0);
});

it('respects the SIDEST_IMAGE_MAX_PIXELS env override', function () {
    config(['sidest.image_max_pixels' => 1_000_000]); // 1 MP

    $service = new ImageVariantService();
    $imageId = seedVariantTestMediaRow();
    // 1200 × 1200 = 1.44 MP, above the configured 1 MP ceiling.
    $fixture = makeVariantFixture(1200, 1200, 'jpeg');

    try {
        $service->processVariants(
            originalTmpPath: $fixture,
            imageId: $imageId,
            basePath: "images/test/{$imageId}",
        );
        throw new \RuntimeException('Expected UnprocessableImageException was not thrown');
    } catch (\App\Services\Media\UnprocessableImageException $e) {
        expect($e->getMessage())->toContain('1000000');
    } finally {
        @unlink($fixture);
    }
});
```

- [ ] **Step 2: Run the new tests to confirm they fail**

Run: `composer test -- --filter=ImageVariantServiceTest`
Expected: The 2 new guard tests FAIL (the guard doesn't exist yet — a 30 MP image will try to decode normally). The 5 tests from Task 2 still pass.

- [ ] **Step 3: Add the `image_max_pixels` config key**

Modify `config/sidest.php`. Find the line `'image_max_upload_size' => (int) env('SIDEST_IMAGE_MAX_UPLOAD_KB', 10240), // 10 MB` (around line 285) and add immediately after it:

```php
    'image_max_upload_size' => (int) env('SIDEST_IMAGE_MAX_UPLOAD_KB', 10240), // 10 MB

    /*
    |----------------------------------------------------------------------
    | Image decode ceiling — pixel count, not file size
    |----------------------------------------------------------------------
    | Refuses to decode any uploaded image whose width × height exceeds
    | this many pixels, BEFORE any bitmap memory is allocated. This is
    | the defense against image-bomb uploads (tiny file, huge resolution)
    | and against legitimate ultra-high-resolution sources that would
    | blow worker memory_limit.
    |
    | Default is 24 MP — above typical phone sensors (12-16 MP), below
    | flagship 48 MP sensors. Conservative for a 256 MB worker memory_limit;
    | can be raised to ~50 MP when workers have more headroom.
    */
    'image_max_pixels' => (int) env('SIDEST_IMAGE_MAX_PIXELS', 24_000_000), // 24 MP
```

- [ ] **Step 4: Add the guard to `loadImage()`**

Modify `app/Services/Media/ImageVariantService.php`. First, add the import near the top of the file (after the existing `use` statements around line 6-8):

Current imports block:
```php
use App\Models\Core\MediaVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
```

(Note: the `use Illuminate\Support\Facades\Http;` line will be deleted in Task 4 — leave it alone for now.)

Because `UnprocessableImageException` lives in the same namespace (`App\Services\Media`), no additional `use` statement is required inside the service.

Then replace the entire `loadImage()` method at lines 330-343 (locate by searching for `private function loadImage`):

Current:
```php
    /**
     * Load an image file into a GD resource regardless of source format.
     */
    private function loadImage(string $path): \GdImage|false
    {
        $info = @getimagesize($path);
        if (!$info) {
            return false;
        }

        return match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => false,
        };
    }
```

New:
```php
    /**
     * Load an image file into a GD resource regardless of source format.
     *
     * Refuses images whose pixel count exceeds sidest.image_max_pixels by
     * throwing UnprocessableImageException BEFORE any bitmap memory is
     * allocated. This is the defense against image-bomb uploads — a tiny
     * JPEG/PNG file can decode to a huge bitmap (4 bytes per pixel), which
     * the worker cannot survive even with the canvas caps applied.
     *
     * The getimagesize() call reads only the file header (a few KB), not
     * the pixel data, so the guard is effectively free.
     */
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

- [ ] **Step 5: Run the guard tests to confirm they now pass**

Run: `composer test -- --filter=ImageVariantServiceTest`
Expected: All 7 tests (5 from Task 2 + 2 new guard tests) PASS.

- [ ] **Step 6: Run the full test suite**

Run: `composer test`
Expected: Full suite green.

- [ ] **Step 7: Commit**

```bash
git add config/sidest.php \
        app/Services/Media/ImageVariantService.php \
        tests/Feature/Media/ImageVariantServiceTest.php
git commit -m "$(cat <<'EOF'
feat(media): add pixel-count guard to loadImage()

Refuses images whose width × height exceeds sidest.image_max_pixels
(default 24 MP, env-overridable via SIDEST_IMAGE_MAX_PIXELS) by throwing
UnprocessableImageException before imagecreatefromjpeg/png/webp allocates
any bitmap memory. Uses values already read by the existing
getimagesize() call — zero additional I/O, zero additional memory.

Defends against:
- Image-bomb uploads (tiny file, huge decoded resolution)
- Legitimate ultra-high-resolution sources that exceed worker memory

24 MP default is conservative for 256 MB worker memory_limit. Can be
raised to ~50 MP once worker memory headroom is verified.

Adds 2 service tests: guard throws on 30 MP input, env override works.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Delete orphaned Shopify-logo helper methods

**Files:**
- Modify: `app/Services/Media/ImageVariantService.php` (delete lines 211-293 and the `Http` import at line 7)

**Why this task:** `downloadRemoteImage()` and `storeOriginalBytes()` were added in commit `6ad4ba9` for `SyncShopifyBrandLogoJob`, which Tobias deleted yesterday in commit `62d3c54` as part of unifying brand design storage. The two methods now have zero callers in the codebase and are pure dead weight. Deleting them in a separate commit keeps the diff focused and gives a clean "chore: cleanup" entry in git blame.

- [ ] **Step 1: Verify zero callers before deletion**

Run: `rg 'downloadRemoteImage|storeOriginalBytes' --type php`
Expected: Only matches are inside `app/Services/Media/ImageVariantService.php` itself. If anything outside that file shows up, STOP and investigate — something has started using these methods since this plan was written.

- [ ] **Step 2: Delete the `Http` import**

Modify `app/Services/Media/ImageVariantService.php`. Delete line 7:

```php
use Illuminate\Support\Facades\Http;
```

Leave the surrounding imports (`App\Models\Core\MediaVariant`, `Illuminate\Http\UploadedFile`, `Illuminate\Support\Facades\Log`, `Illuminate\Support\Facades\Storage`) alone.

- [ ] **Step 3: Delete both method bodies**

Modify `app/Services/Media/ImageVariantService.php`. Delete lines 211-293 (locate by searching for `* Download a remote image into memory` — the docblock that starts the region). The region to delete begins with the blank line separating `storeOriginal()` from `downloadRemoteImage()` and ends just before the docblock for `deleteVariants()`.

The full region to delete (for unambiguity):

```php

    /**
     * Download a remote image into memory and return its bytes + sniffed metadata.
     *
     * Caller is responsible for restricting the URL host when SSRF is a concern —
     * this helper only enforces scheme, size, and content-type. Currently used by
     * background sync jobs that pull brand assets from vetted third-party CDNs.
     *
     * @return array{bytes: string, size: int, mime: string, ext: string, sha256: string}
     */
    public function downloadRemoteImage(
        string $url,
        int $maxBytes = 5_242_880,
        int $timeoutSeconds = 20,
    ): array {
        $parsed = parse_url($url);
        if (! is_array($parsed)) {
            throw new \RuntimeException('Invalid image URL.');
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new \RuntimeException('Remote image URL must use https.');
        }

        $response = Http::timeout($timeoutSeconds)->get($url);

        if (! $response->ok()) {
            throw new \RuntimeException("Image fetch failed (HTTP {$response->status()}).");
        }

        $bytes = $response->body();
        $size  = strlen($bytes);

        if ($size === 0) {
            throw new \RuntimeException('Remote image response body is empty.');
        }
        if ($size > $maxBytes) {
            throw new \RuntimeException("Remote image exceeds maximum size ({$size} > {$maxBytes} bytes).");
        }

        // Sniff the mime from the bytes rather than trusting response headers,
        // so a spoofed Content-Type can't slip non-image content into the pipeline.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) ($finfo->buffer($bytes) ?: '');

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => throw new \RuntimeException("Unsupported remote image type: {$mime}"),
        };

        return [
            'bytes'  => $bytes,
            'size'   => $size,
            'mime'   => $mime,
            'ext'    => $ext,
            'sha256' => hash('sha256', $bytes),
        ];
    }

    /**
     * Persist raw image bytes to the media disk using the same content-hashed
     * filename scheme as {@see storeOriginal()}. Kept as a sibling so both entry
     * points (UploadedFile and in-memory bytes) share a stable path convention.
     *
     * Returns the storage path of the original.
     */
    public function storeOriginalBytes(
        string $bytes,
        string $basePath,
        string $ext,
        string $sha256,
    ): string {
        $hash = substr($sha256, 0, 16);
        $path = "{$basePath}/original_{$hash}.{$ext}";

        $this->disk()->put($path, $bytes, 'public');

        return $path;
    }
```

After deletion, the method right above the deleted block is `storeOriginal()` and the method right below is `deleteVariants()`. There should be exactly one blank line between them.

- [ ] **Step 4: Run the full test suite**

Run: `composer test`
Expected: Full suite green. If anything fails here, it means something secretly depended on one of the deleted methods — investigate before proceeding.

- [ ] **Step 5: Run Pint for code style**

Run: `php artisan pint app/Services/Media/ImageVariantService.php`
Expected: Zero changes, or minor whitespace cleanup.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Media/ImageVariantService.php
git commit -m "$(cat <<'EOF'
chore(media): delete orphaned Shopify brand-logo helper methods

downloadRemoteImage() and storeOriginalBytes() were added in 6ad4ba9
for SyncShopifyBrandLogoJob, which was deleted in 62d3c54 when brand
design storage was unified. Both methods now have zero callers in the
codebase and can be removed safely.

Also drops the use Illuminate\Support\Facades\Http import that was
added specifically for downloadRemoteImage().

Verified zero callers via rg 'downloadRemoteImage|storeOriginalBytes'.
Full test suite passes after removal.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Job-layer retry-skip for `UnprocessableImageException`

**Files:**
- Modify: `app/Jobs/ProcessImageVariantsJob.php` (imports + `handle()` catch block)
- Create: `tests/Feature/Jobs/ProcessImageVariantsJobTest.php`

**Why this task:** With the guard in place, a too-large image will throw `UnprocessableImageException` from inside `processVariants()`. Without this task, that exception bubbles into the generic `catch (Throwable $e)` branch, which rethrows and triggers Laravel's retry machinery (`$tries = 3`, `$backoff = 30`). That wastes ~90 seconds on 3 guaranteed-to-fail retries. This task adds a dedicated catch branch that recognizes the permanent-failure type, calls `$this->markFailed()` to populate `processing_error`, calls `$this->fail()` to mark the job failed on the first attempt, and returns without rethrowing. Transient failures (R2 unavailable, temp file IO) continue to retry normally.

Tests use the `withFakeQueueInteractions()` / `assertFailed()` pattern from `tests/Unit/MediaJobReliabilityTest.php` (Laravel 12 built-in) — catches `$this->fail()` calls cleanly without actually failing the test.

- [ ] **Step 1: Write the failing job tests**

Create `tests/Feature/Jobs/ProcessImageVariantsJobTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Media\UnprocessableImageException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupMediaTables();

    $testRoot = storage_path('framework/testing/disks/process-image-variants-job');
    config([
        'sidest.media_disk' => 'local',
        'filesystems.disks.local.root' => $testRoot,
    ]);

    if (! is_dir($testRoot)) {
        mkdir($testRoot, 0777, true);
    }
});

function seedJobTestMediaRow(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => (string) Str::uuid(),
        'pool' => 'gallery',
        'path' => '',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    return $id;
}

it('marks the SiteMedia row as ready on successful processing', function () {
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andReturn([
        'optimized' => new \stdClass(),
        'maximized' => new \stdClass(),
    ]);

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->handle($service);

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_READY);
    expect($row->processing_error)->toBeNull();
});

it('fails immediately without retrying when processVariants throws UnprocessableImageException', function () {
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andThrow(
        new UnprocessableImageException('Image dimensions exceed safe processing limit (6000 x 5000 = 30000000 pixels, max 24000000).')
    );

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->withFakeQueueInteractions();
    $job->handle($service);

    // The job must call $this->fail() internally — assertFailed() verifies that.
    $job->assertFailed();

    $row = SiteMedia::query()->findOrFail($imageId);
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_FAILED);
    expect((string) $row->processing_error)->toContain('exceed safe processing limit');
    expect((string) $row->processing_error)->toContain('6000');
});

it('rethrows transient failures so Laravel retries them normally', function () {
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andThrow(
        new RuntimeException('transient boom')
    );

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");

    // Generic Throwable is rethrown, which Laravel's queue worker would catch
    // and reschedule. We verify the rethrow and the in-progress processing state.
    expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'transient boom');

    $row = SiteMedia::query()->findOrFail($imageId);
    // State stays PROCESSING because markFailed only runs in the terminal failed() handler
    // for transient errors — the row moves to FAILED only after $tries is exhausted.
    expect($row->processing_state)->toBe(SiteMedia::PROCESSING_STATE_PROCESSING);
});

it('records the guard error message in processing_error so the frontend can surface it', function () {
    $imageId = seedJobTestMediaRow();
    $originalPath = "images/test/{$imageId}/original.jpg";
    Storage::disk('local')->put($originalPath, 'image-bytes');

    $service = Mockery::mock(ImageVariantService::class);
    $service->shouldReceive('resolvedDiskName')->once()->andReturn('local');
    $service->shouldReceive('processVariants')->once()->andThrow(
        new UnprocessableImageException('Image dimensions exceed safe processing limit (8000 x 8000 = 64000000 pixels, max 24000000).')
    );

    $job = new ProcessImageVariantsJob($originalPath, $imageId, "images/test/{$imageId}");
    $job->withFakeQueueInteractions();
    $job->handle($service);

    $job->assertFailed();

    $row = SiteMedia::query()->findOrFail($imageId);
    $error = (string) $row->processing_error;
    expect($error)->not->toBeEmpty();
    expect($error)->toContain('8000');
    expect($error)->toContain('64000000');
    expect($error)->toContain('24000000');
});
```

- [ ] **Step 2: Run the failing tests to confirm they fail**

Run: `composer test -- --filter=ProcessImageVariantsJobTest`
Expected:
- `marks the SiteMedia row as ready on successful processing` — PASS (no changes required, this happy path works today).
- `fails immediately without retrying when processVariants throws UnprocessableImageException` — FAIL. The current catch branch rethrows, so `handle()` throws the exception out rather than calling `$this->fail()`. `assertFailed()` won't see the fail call.
- `rethrows transient failures so Laravel retries them normally` — PASS (current behavior is rethrow, which is what we still want for transient errors).
- `records the guard error message in processing_error` — FAIL (same reason as test 2 — the job never gets to write the error before rethrowing).

- [ ] **Step 3: Add the `UnprocessableImageException` catch branch in `ProcessImageVariantsJob::handle()`**

Modify `app/Jobs/ProcessImageVariantsJob.php`. First, add the import after the existing imports (around line 5-14):

Current:
```php
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
```

New:
```php
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Media\UnprocessableImageException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
```

Then replace the existing catch block inside `handle()` at lines 114-121 (locate by searching for `} catch (Throwable $e) {` inside `handle`):

Current:
```php
        } catch (Throwable $e) {
            Log::error('ProcessImageVariantsJob: variant generation failed.', [
                'image_id'  => $this->imageId,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw $e;
        } finally {
```

New:
```php
        } catch (UnprocessableImageException $e) {
            // Permanent validation failure (e.g. pixel-count guard rejection).
            // Retrying cannot succeed, so mark failed immediately and skip the
            // retry machinery that $tries = 3 would otherwise trigger.
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

- [ ] **Step 4: Run the job tests to confirm they now pass**

Run: `composer test -- --filter=ProcessImageVariantsJobTest`
Expected: All 4 tests PASS.

- [ ] **Step 5: Run the full test suite**

Run: `composer test`
Expected: Full suite green. Particularly verify `tests/Unit/MediaJobReliabilityTest.php` still passes — it has a test that throws a generic `RuntimeException('image boom')` and expects the old rethrow behavior, which should still work because the new catch branch only fires on `UnprocessableImageException`.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessImageVariantsJob.php \
        tests/Feature/Jobs/ProcessImageVariantsJobTest.php
git commit -m "$(cat <<'EOF'
feat(jobs): skip retries on UnprocessableImageException

Adds a dedicated catch (UnprocessableImageException $e) branch in
ProcessImageVariantsJob::handle() that calls markFailed() and fail()
on the first attempt rather than rethrowing. Rethrowing would trigger
Laravel's $tries = 3 retry machinery, which wastes ~90 seconds retrying
a guard rejection that can never succeed.

Transient failures (generic Throwable) continue to rethrow and retry
normally via the second catch branch.

Adds 4 job-level tests:
- happy path marks ready
- UnprocessableImageException fails immediately and skips retries
- generic transient failures still rethrow
- processing_error gets the guard message for frontend surfacing

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Full verification and PR prep

**Files:** None modified. This is a verification and hygiene pass.

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: Every test green, including the 9 new tests across `ImageVariantServiceTest` (7) and `ProcessImageVariantsJobTest` (4). Also verify `MediaJobReliabilityTest` and `MediaUploadFailureHandlingTest` remain unaffected.

- [ ] **Step 2: Run Pint across all touched files**

Run:
```bash
php artisan pint app/Services/Media/ImageVariantService.php \
                 app/Services/Media/UnprocessableImageException.php \
                 app/Jobs/ProcessImageVariantsJob.php \
                 config/sidest.php \
                 tests/Feature/Media/ImageVariantServiceTest.php \
                 tests/Feature/Jobs/ProcessImageVariantsJobTest.php
```
Expected: Zero style violations, or minor cleanup that Pint fixes automatically.

- [ ] **Step 3: Verify dead-code deletion is actually complete**

Run: `rg 'downloadRemoteImage|storeOriginalBytes|Illuminate\\\\Support\\\\Facades\\\\Http' app/Services/Media/`
Expected: Zero matches in `ImageVariantService.php`. The `Http` facade import and both method bodies should be gone.

- [ ] **Step 4: Smoke-check the dev environment end-to-end**

If you want to exercise the full path against a real Horizon worker (optional but recommended since tests don't measure peak memory):

```bash
composer dev
```

Then in another terminal, upload a 4000×3000 test image via the API:
```bash
# Example — adjust auth / site id / pool as needed
curl -F "pool=gallery" \
     -F "image=@/path/to/test-4000x3000.jpg" \
     -H "Authorization: Bearer <token>" \
     https://your-dev-url/api/uploads
```

Check Horizon for the job result. In Nightwatch (or `storage/logs/laravel.log`), watch for:
- `ProcessImageVariantsJob: completed.` log line
- `SiteMedia` row with `processing_state = 'ready'`
- Two `MediaVariant` rows with widths 2400 and 4000 respectively

Then upload a deliberately oversized image (e.g. 6000×5000 = 30 MP) and verify:
- Job logs `ProcessImageVariantsJob: unprocessable image, failing without retry.`
- `SiteMedia.processing_state = 'failed'`
- `processing_error` contains the guard message
- Horizon shows 1 attempt (not 3)

If smoke test is skipped, note this explicitly in the PR description so a reviewer knows to gate on Nightwatch post-deploy.

- [ ] **Step 5: Create the pull request**

Run (adjust the PR body as needed):
```bash
gh pr create --base main --title "fix(media): bound image variant memory and add pixel-count guard" --body "$(cat <<'EOF'
## Summary
- Drops `preserve_resolution => true` on both default image variants; replaces with explicit `width`/`height`/`fit` caps (optimized: 2400px, maximized: 4000px). Originals stay untouched via `storeOriginal()`.
- Adds a megapixel guard in `ImageVariantService::loadImage()` that refuses images exceeding 24 MP (env-overridable via `SIDEST_IMAGE_MAX_PIXELS`) BEFORE any GD decode runs. Uses values already read by the existing `getimagesize()` call.
- Introduces `UnprocessableImageException`; makes `ProcessImageVariantsJob` skip Laravel's retry machinery on permanent validation failures (saves ~90s of wasted retries per rejection).
- Deletes orphaned `downloadRemoteImage()` / `storeOriginalBytes()` methods from `ImageVariantService` (zero callers since #62d3c54 removed `SyncShopifyBrandLogoJob`).
- Net-new test coverage: 7 service tests + 4 job tests covering variant dimensions, aspect preservation, guard behavior, env override, and retry-skip semantics.

Full design doc: `docs/superpowers/specs/2026-04-15-image-memory-bound-design.md`

## Test plan
- [x] `composer test -- --filter=ImageVariantServiceTest` → 7 green
- [x] `composer test -- --filter=ProcessImageVariantsJobTest` → 4 green
- [x] `composer test` → full suite green
- [x] `php artisan pint` → zero style violations
- [x] `rg 'downloadRemoteImage|storeOriginalBytes'` → zero matches (dead code fully deleted)
- [ ] Manual smoke: upload a 4000x3000 image via dev env, verify two variants ready
- [ ] Manual smoke: upload a 6000x5000 image, verify single-attempt failure with guard message
- [ ] Post-deploy: monitor Nightwatch for `ProcessImageVariantsJob` failure rate and absence of PHP fatals mentioning `imagecreatetruecolor`
EOF
)"
```

---

## Rollback plan

If anything goes sideways after merge:

1. **Loosen the guard without redeploying:** set `SIDEST_IMAGE_MAX_PIXELS=100000000` in env to disable the guard for any realistic image.
2. **Raise the quality ceilings:** set `SIDEST_IMAGE_MAXIMIZED_QUALITY=100` and/or `SIDEST_IMAGE_QUALITY=100` if visible quality regressions are reported.
3. **Full revert:** `git revert` the merge commit. No database migrations, no data loss on rollback — previously-uploaded images' `MediaVariant` rows are untouched by this change and continue to work.

---

## Out of scope (deferred)

- FormRequest-layer pixel validation (immediate-feedback UX at upload time instead of background-job failure). Worth a separate small task.
- Replacing GD with `intervention/image` (would also solve EXIF orientation bugs). Worth a separate brainstorming session.
- Re-processing existing uploads under the new caps. Pre-beta, not needed.
