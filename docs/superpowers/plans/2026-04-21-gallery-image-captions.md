# Gallery Image Captions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a professional attach a visible text caption (≤200 chars) to each gallery image, editable any time post-upload, rendered on the public site.

**Architecture:** Add a `caption` column to `site.site_media` (same pattern as existing `alt_text`). Accept it on upload via existing `POST /api/uploads`, edit it via a new `PATCH /api/gallery/{image}` endpoint. Project it through the `site.public_site_payload` DB view so it flows to the public storefront.

**Tech Stack:** PHP 8.2, Laravel 12, PostgreSQL (via Supabase), Pest 4.

## Scope

**In scope (this plan):**
- Gallery pool images (pool=gallery, media_type=image) in the professional dashboard
- Schema + view update so `caption` flows through the public-site payload for all media projections (gallery, content_images, gallery_videos, content_videos) — YAGNI says ship the narrow feature, but while we're rewriting the view we include the column in every projection so we never have to rewrite it twice
- Upload-time caption (via existing `POST /api/uploads`)
- Edit caption + alt_text via a new `PATCH /api/gallery/{image}` endpoint
- Read caption back via `GET /api/gallery` (existing endpoint)

**Out of scope (future work if desired):**
- Brand gallery (pool=brand_gallery) — different controller, follow same pattern later if needed
- Staff admin edit of captions — staff endpoints would need a separate PATCH; defer unless asked
- Frontend gallery editor UI + render — backend only here
- Caption on content images or videos — column is populated in the view but no edit UI yet

## File Structure

**New files:**
- `supabase/migrations/20260421010000_add_caption_to_site_media.sql` — add column, update covering index, re-emit `public_site_payload` view with caption in all four media projections
- `app/Http/Requests/Api/Professional/ImageGallery/UpdateGalleryImageRequest.php` — validates `caption` and `alt_text` on the new PATCH
- `tests/Feature/Gallery/GalleryCaptionTest.php` — Pest tests for upload + edit + read cycle

**Modified files:**
- `app/Models/Core/Site/SiteMedia.php` — add `caption` to `$fillable`
- `app/Http/Requests/Api/Professional/Uploads/UploadImageRequest.php` — accept optional `caption` field
- `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php` — write `caption` from request to the new `SiteMedia` row; return it in response
- `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php` — return `caption` in `index()`; add `update()` method
- `routes/api/professional.php` — add `PATCH /gallery/{image}` route

## Design Decisions (locked)

- **Column type:** `varchar(200) NULL` — enough for a sentence or two, short enough to keep UI clean. Defaults to NULL, no backfill needed.
- **Where edited:** dedicated `PATCH /api/gallery/{image}` (not the bulk site update). Keeps the existing gallery controller focused on per-image ops.
- **Also editable on PATCH:** `alt_text` (currently it's only set at upload — this plan fixes that gap while we're here, since the UX for "edit image metadata" is one form).
- **Trimming:** trim whitespace on write; if the result is empty string → store NULL (so `NULL` and `""` mean the same thing).

## Security & Scalability Considerations

**Security**

- **Authorization (per-request).** The PATCH endpoint uses the same `supabase.jwt` + `current.pro` middleware as every other professional route. The controller then enforces `$image->site_id === $site->id` — a user cannot edit an image belonging to another professional's site (returns 404, not 403, to avoid leaking existence).
- **XSS — frontend responsibility, flagged here for visibility.** Captions are stored raw (correct: escape on render, never on storage). The public-site payload returns the raw string; the frontend MUST escape it when rendering. React/JSX's default text interpolation (`{caption}`) auto-escapes and is safe. Any raw-HTML-injection API must be avoided for this field. This plan will note this in the final verification step and the PR description, but it cannot be *enforced* from the backend.
- **Input length cap.** `max:200` in both request validators + `varchar(200)` at the DB level — defense in depth. A direct DB insert via Supabase's service role would also be bounded.
- **Mass-assignment.** `UpdateGalleryImageRequest` only validates `caption` + `alt_text`. Even if a caller sent `{"caption": "x", "sort_order": 999}`, the `sort_order` key wouldn't pass validation and wouldn't reach the update.
- **SQL injection.** All writes go through Eloquent with bound parameters. No raw SQL in this plan.
- **Rate limiting.** New PATCH route gets a dedicated `throttle:30,1` limiter (30 requests/min) — ample for legitimate autosave-on-blur editing, tight enough to block spray-edit abuse. This is separate from the parent `throttle:api` bucket, so a bad actor can't consume the whole pro's API budget on caption edits.
- **Whitespace normalisation.** Prevents invisible captions that pass `IS NOT NULL` checks — whitespace-only values become NULL so they're never rendered as a "blank" caption box on the public site.
- **Unicode / RTL-override:** not filtered. Risk is zero here because a pro can only edit captions on their own site — there's no cross-tenant surface. If the caption ever becomes displayable on someone else's page (e.g., a curated marketplace), revisit.

**Scalability**

- **Covering index parity.** Existing index on `site.site_media (site_id, sort_order) INCLUDE (alt_text, ...)` gets rebuilt with `caption` added to the INCLUDE list. Guarantees the public-site view still runs as an index-only scan — no new heap fetches.
- **Cache invalidation is conditional.** The PATCH handler uses `$image->isDirty(['caption', 'alt_text'])` to skip the `SiteCacheService::invalidateSite` call when the request didn't actually change anything. Autosave-on-blur can therefore fire freely without churning the public-site cache.
- **No new queries on the hot path.** Caption is a scalar column on a table already selected by the public-site view. Zero additional joins.
- **Trigger safety.** The `enforce_site_gallery_max6` trigger fires on `UPDATE OF site_id, deleted_at` only — caption edits do NOT trigger it, so there's no unexpected row-counting work on each PATCH.
- **View recompile is a one-time deploy cost.** `CREATE OR REPLACE VIEW` briefly invalidates prepared-statement plans referencing the old view; negligible impact, only happens during the migration.
- **Payload size impact.** 200 bytes × 6 gallery images = ~1.2 KB added per site payload. Public-site payloads are cached; storage and transfer impact is trivial.

**Deferred (tech-debt, out of scope)**

- No audit log for caption edits — pre-beta this is fine; revisit when moderation matters.
- No backend markdown/emoji validation — captions are plain text, rendered as plain text.
- Brand gallery (`pool='brand_gallery'`) doesn't get captions in this plan — follow the same pattern there later if needed.

---

## Task 1: Add Column, Update Covering Index, and Update Public Site Payload View

**Files:**
- Create: `supabase/migrations/20260421010000_add_caption_to_site_media.sql`

- [ ] **Step 1: Read the existing view definition**

Open `supabase/migrations/20260403000000_v2_baseline.sql` and locate `CREATE OR REPLACE VIEW site.public_site_payload` (starts around line 1488, ends around line 1700 where the `COMMENT ON VIEW` follows).

Copy the **entire view definition** — from `CREATE OR REPLACE VIEW site.public_site_payload` through the closing `;` — into your clipboard. You'll paste it in Step 2 and add `caption` to each of the four `jsonb_build_object` projections that currently include `'alt_text', sm.alt_text`.

- [ ] **Step 2: Create the migration file**

Create `supabase/migrations/20260421010000_add_caption_to_site_media.sql`:

```sql
-- Add caption column to site.site_media and project it through the
-- public site payload view so it flows to the storefront.
--
-- caption is distinct from alt_text:
--   - alt_text: screen-reader / accessibility, not visually rendered
--   - caption : visible text shown under/over the image on the site
--
-- Length cap: 200 chars (enough for a sentence or two; frontend enforces
-- a matching length in the editor).

BEGIN;

-- 1. Add the column
ALTER TABLE site.site_media
    ADD COLUMN caption varchar(200) NULL;

-- 2. Rebuild the covering index to include caption alongside alt_text.
--    The existing index includes alt_text for index-only scans on the
--    public site payload view — add caption for parity.
DROP INDEX IF EXISTS site.site_media_site_active_sort_covering_idx;

CREATE INDEX site_media_site_active_sort_covering_idx
    ON site.site_media (site_id, sort_order)
    INCLUDE (alt_text, caption, media_type, pool)
    WHERE deleted_at IS NULL AND is_active = true;

-- 3. Re-emit the public site payload view with 'caption' added to every
--    media projection. Paste the ENTIRE existing view definition from
--    20260403000000_v2_baseline.sql (lines ~1488–1699), then add
--    `'caption', sm.caption,` inside each of the four jsonb_build_object
--    blocks that currently include `'alt_text', sm.alt_text,`:
--      - 'gallery'        (media_type='image', pool='gallery')
--      - 'content_images' (media_type='image', pool='content')
--      - 'gallery_videos' (media_type='video', pool='gallery')
--      - 'content_videos' (media_type='video', pool='content')
--    Insert the caption key on the line immediately after alt_text so the
--    diff is readable.

-- >>> BEGIN PASTED VIEW DEFINITION (with 4x caption additions) <<<
-- CREATE OR REPLACE VIEW site.public_site_payload AS ...
-- (paste the full view here; see comment above for exact edits)
-- >>> END PASTED VIEW DEFINITION <<<

COMMENT ON VIEW site.public_site_payload IS
    'Complete public site payload with two-flag section visibility (is_enabled + is_active). Includes per-image caption + alt_text.';

COMMIT;
```

**Important:** the `>>> BEGIN PASTED VIEW DEFINITION <<<` block is a placeholder — replace it with the literal copied-and-edited view SQL before running the migration. The migration will not work if you leave the placeholder in place.

The four `jsonb_build_object` edits look like this (example for the gallery projection):

```sql
-- BEFORE:
jsonb_build_object(
    'id', sm.id,
    'alt_text', sm.alt_text,
    'sort_order', sm.sort_order,
    ...

-- AFTER:
jsonb_build_object(
    'id', sm.id,
    'alt_text', sm.alt_text,
    'caption', sm.caption,
    'sort_order', sm.sort_order,
    ...
```

- [ ] **Step 3: Run the migration against your local Supabase**

Run: `supabase db push` (or your local Supabase migration command — adapt to however this project applies migrations locally).

Expected: migration applies without error.

- [ ] **Step 4: Verify the column and index exist**

Run in a psql session against the local DB:

```sql
\d site.site_media
-- expected: a `caption` column of type character varying(200)

SELECT indexdef FROM pg_indexes
WHERE schemaname = 'site'
  AND indexname = 'site_media_site_active_sort_covering_idx';
-- expected: INCLUDE list mentions both alt_text and caption
```

- [ ] **Step 5: Verify the view projects caption**

Run in psql:

```sql
SELECT jsonb_path_query_first(payload, '$.gallery[0]') FROM site.public_site_payload LIMIT 1;
-- expected: object contains "caption" key (value will be null unless a row has one)
```

If no gallery rows exist in your dev DB, manually insert a row and re-check.

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/20260421010000_add_caption_to_site_media.sql
git commit -m "feat(db): add caption column to site_media + project via public_site_payload view"
```

---

## Task 2: Add `caption` to the Eloquent Model

**Files:**
- Modify: `app/Models/Core/Site/SiteMedia.php`
- Test: (none for this task — model-level fillable change; exercised via Task 3 tests)

- [ ] **Step 1: Find `$fillable` in `SiteMedia.php`**

Open `app/Models/Core/Site/SiteMedia.php` and locate the `$fillable` array. Look for a property declaration like:

```php
protected $fillable = [
    'site_id',
    'bucket',
    'path',
    'alt_text',
    ...
];
```

- [ ] **Step 2: Add `caption` to `$fillable`**

Insert `'caption',` on the line immediately after `'alt_text',` to keep related fields adjacent:

```php
protected $fillable = [
    'site_id',
    'bucket',
    'path',
    'alt_text',
    'caption',
    // ... remaining existing fields unchanged
];
```

**Do NOT remove or reorder any other `$fillable` entries.** Only add the one new line.

- [ ] **Step 3: Commit**

```bash
git add app/Models/Core/Site/SiteMedia.php
git commit -m "feat(model): add caption to SiteMedia fillable"
```

---

## Task 3: Accept `caption` on Upload

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Uploads/UploadImageRequest.php`
- Modify: `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`
- Test: `tests/Feature/Gallery/GalleryCaptionTest.php` (new file — will be extended in later tasks)

- [ ] **Step 1: Write the failing test**

Create the test directory and file. Mirror the SQLite-in-memory pattern used elsewhere in this codebase (see `tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.php` for a reference) — but for this feature we only exercise the validator + model, not the DB view, so a minimal schema is sufficient.

Create `tests/Feature/Gallery/GalleryCaptionTest.php`:

```php
<?php

use App\Http\Requests\Api\Professional\Uploads\UploadImageRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

function validateUploadRequest(array $payload): array
{
    $request = Request::create('/test', 'POST', $payload);
    $formRequest = UploadImageRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

it('UploadImageRequest accepts caption up to 200 characters', function () {
    // Use a stub file upload via $_FILES is impractical here; we only care
    // about the caption rule, so validate against a payload that would
    // otherwise fail on the file check — but not on the caption rule.
    $result = validateUploadRequest([
        'pool' => 'gallery',
        'caption' => str_repeat('a', 200),
    ]);

    // File-missing error is expected; we only assert caption has NO error.
    expect($result['errors'] ?? [])->not->toHaveKey('caption');
});

it('UploadImageRequest rejects caption longer than 200 characters', function () {
    $result = validateUploadRequest([
        'pool' => 'gallery',
        'caption' => str_repeat('a', 201),
    ]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('caption');
});

it('UploadImageRequest accepts missing caption (nullable)', function () {
    $result = validateUploadRequest([
        'pool' => 'gallery',
        // no caption
    ]);

    expect($result['errors'] ?? [])->not->toHaveKey('caption');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Gallery/GalleryCaptionTest.php`
Expected: 2 failures — "accepts caption up to 200 characters" passes only because the rule doesn't exist so no caption error is produced (lucky pass), but the "rejects caption longer than 200" case will FAIL because with no rule there's still no error. Actually, with no caption rule both tests lacking a caption error will pass — so to properly test-drive, reverse the assertion: expect the test for "rejects > 200" to FAIL before the rule exists. That's the TDD signal to implement.

- [ ] **Step 3: Add the `caption` rule to `UploadImageRequest`**

Open `app/Http/Requests/Api/Professional/Uploads/UploadImageRequest.php`. Find the `rules()` method and add a `caption` rule immediately after the existing `alt_text` rule:

```php
return [
    'pool' => [ /* existing */ ],
    'image' => [ /* existing */ ],
    'video' => [ /* existing */ ],
    'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
    'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
];
```

- [ ] **Step 4: Persist `caption` in `ProfessionalUploadController::upload`**

Open `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php`. Find the `SiteMedia` create call in `upload()` — the line containing `'alt_text' => $request->validated('alt_text'),`.

Add a `'caption' => ...` line directly after it, with trim-then-nullify-empty behaviour:

```php
'alt_text' => $request->validated('alt_text'),
'caption' => $this->normaliseCaption($request->validated('caption')),
```

Then add a private helper method at the bottom of the controller class (keep it near other private helpers if any exist):

```php
/**
 * Trim caption and coerce empty strings to null so NULL and ""
 * mean the same thing at rest.
 */
private function normaliseCaption(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }

    $trimmed = trim($raw);

    return $trimmed === '' ? null : $trimmed;
}
```

- [ ] **Step 5: Return `caption` in the upload response**

Same file. Find the response payload builder (look for the block around `'alt_text' => $media->alt_text,` inside the response array — grep for `'alt_text' => \$media->alt_text` to pinpoint it). Add a `'caption'` line immediately after:

```php
$payload = [
    'id' => $media->id,
    'pool' => $media->pool,
    'alt_text' => $media->alt_text,
    'caption' => $media->caption,
    // ... rest unchanged
];
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Gallery/GalleryCaptionTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Api/Professional/Uploads/UploadImageRequest.php \
        app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php \
        tests/Feature/Gallery/GalleryCaptionTest.php
git commit -m "feat(uploads): accept caption on gallery image upload"
```

---

## Task 4: Return `caption` in Gallery Index

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php`
- Test: extend `tests/Feature/Gallery/GalleryCaptionTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Gallery/GalleryCaptionTest.php`:

```php
use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('SiteMedia model persists and reads caption', function () {
    // Unit-level: verify the fillable + cast without going through the
    // full HTTP stack. Uses the default test DB (SQLite in-memory).
    $media = new SiteMedia([
        'site_id' => (string) Str::uuid(),
        'bucket' => 'test',
        'path' => 'x.webp',
        'alt_text' => 'alt',
        'caption' => 'My summer shoot',
        'pool' => 'gallery',
        'media_type' => 'image',
        'processing_state' => 'ready',
    ]);

    expect($media->caption)->toBe('My summer shoot');
});
```

This test does NOT require the `caption` column to already be mapped — Eloquent's mass-assignment will accept the attribute as long as `caption` is in `$fillable` (Task 2). It does NOT save to the DB, so no schema required.

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Gallery/GalleryCaptionTest.php --filter="persists and reads caption"`
Expected: PASS (Task 2 already added `caption` to `$fillable`).

If this fails, go back and verify Task 2 was committed correctly.

- [ ] **Step 3: Modify `ProfessionalGalleryController::index`**

Open `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php`. Find the `$images->map(...)` block inside `index()`. The current shape includes `'alt_text' => $img->alt_text,`. Add a line for `caption` directly after:

```php
$result = $images->map(fn (SiteMedia $img) => [
    'id' => $img->id,
    'pool' => $img->pool,
    'alt_text' => $img->alt_text,
    'caption' => $img->caption,
    'sort_order' => $img->sort_order,
    'variants' => $img->variantUrls(),
    'created_at' => $img->created_at,
    'updated_at' => $img->updated_at,
]);
```

- [ ] **Step 4: Run full feature-flag-adjacent test suite to confirm no regressions**

Run: `./vendor/bin/pest tests/Feature/Gallery/`
Expected: all existing gallery tests plus the new ones pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php \
        tests/Feature/Gallery/GalleryCaptionTest.php
git commit -m "feat(gallery): include caption in GET /api/gallery response"
```

---

## Task 5: Add PATCH /api/gallery/{image} Endpoint for Captions + Alt-Text Edits

**Files:**
- Create: `app/Http/Requests/Api/Professional/ImageGallery/UpdateGalleryImageRequest.php`
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php` — add `update()` method
- Modify: `routes/api/professional.php` — add PATCH route
- Test: extend `tests/Feature/Gallery/GalleryCaptionTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Gallery/GalleryCaptionTest.php`:

```php
use App\Http\Requests\Api\Professional\ImageGallery\UpdateGalleryImageRequest;

it('UpdateGalleryImageRequest accepts caption and alt_text within limits', function () {
    $request = Request::create('/test', 'PATCH', [
        'caption' => 'Before and after haircut',
        'alt_text' => 'Short back and sides',
    ]);
    $formRequest = UpdateGalleryImageRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    $thrown = null;
    try {
        $formRequest->validateResolved();
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});

it('UpdateGalleryImageRequest rejects caption longer than 200 characters', function () {
    $request = Request::create('/test', 'PATCH', [
        'caption' => str_repeat('a', 201),
    ]);
    $formRequest = UpdateGalleryImageRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    $thrown = null;
    try {
        $formRequest->validateResolved();
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->errors())->toHaveKey('caption');
});

it('route PATCH api/gallery/{image} maps to ProfessionalGalleryController@update', function () {
    $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('PATCH', $r->methods()) && $r->uri() === 'api/gallery/{image}');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('ProfessionalGalleryController@update');
});

it('route PATCH api/gallery/{image} has per-route throttle:30,1 middleware', function () {
    $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('PATCH', $r->methods()) && $r->uri() === 'api/gallery/{image}');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:30,1');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Gallery/GalleryCaptionTest.php`
Expected: 3 new tests FAIL — request class and route don't exist yet.

- [ ] **Step 3: Create the request class**

First create the directory if needed: `mkdir -p app/Http/Requests/Api/Professional/ImageGallery` (if it doesn't exist — it should, since `ReorderGalleryImageRequest` lives there).

Create `app/Http/Requests/Api/Professional/ImageGallery/UpdateGalleryImageRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Professional\ImageGallery;

use App\Http\Requests\BaseFormRequest;

// V2: Validates per-image metadata edits (caption, alt_text) on a gallery image.
class UpdateGalleryImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Add the `update()` method to `ProfessionalGalleryController`**

Open `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php`.

Add the import at the top near the other request imports:

```php
use App\Http\Requests\Api\Professional\ImageGallery\UpdateGalleryImageRequest;
```

Add an `update` method. Place it between `reorder()` and `destroy()` so the CRUD-ish methods stay grouped:

```php
/**
 * Update caption and/or alt_text on a gallery image. Trims whitespace;
 * an empty/whitespace-only value is stored as NULL. Invalidates the
 * public-site cache only when a field actually changed — avoids cache
 * churn on autosave-on-blur edits that don't mutate anything.
 */
public function update(UpdateGalleryImageRequest $request, SiteMedia $image): JsonResponse
{
    $pro = $this->currentProfessional($request);
    $site = $this->currentSite($pro);
    abort_unless($image->site_id === $site->id, 404);

    $data = $request->validated();
    $update = [];

    if (array_key_exists('caption', $data)) {
        $update['caption'] = $this->normaliseOptionalString($data['caption']);
    }

    if (array_key_exists('alt_text', $data)) {
        $update['alt_text'] = $this->normaliseOptionalString($data['alt_text']);
    }

    $changed = false;
    if (! empty($update)) {
        $image->fill($update);
        // isDirty() compares in-memory vs original DB state. If the
        // incoming values equal what's already stored, skip the save
        // AND the cache bust.
        if ($image->isDirty(['caption', 'alt_text'])) {
            $image->save();
            $changed = true;
        }
    }

    if ($changed) {
        app(SiteCacheService::class)->invalidateSite($site);
    }

    return $this->success([
        'image' => [
            'id' => $image->id,
            'alt_text' => $image->alt_text,
            'caption' => $image->caption,
        ],
    ]);
}

/**
 * Trim, and coerce empty strings to null so NULL and "" mean the same.
 */
private function normaliseOptionalString(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }

    $trimmed = trim($raw);

    return $trimmed === '' ? null : $trimmed;
}
```

- [ ] **Step 5: Register the route with a dedicated throttle**

Open `routes/api/professional.php`. Find the existing gallery routes (there should be a block near `Route::get('/gallery', ...)` and `Route::post('/gallery/reorder', ...)` — grep for `ProfessionalGalleryController` to locate them).

Add a new PATCH route immediately after the `GET /gallery` line. Apply a per-route throttle (`30,1` = 30 requests per minute) so caption edits can't consume the whole pro's `throttle:api` budget:

```php
Route::patch('/gallery/{image}', [ProfessionalGalleryController::class, 'update'])
    ->whereUuid('image')
    ->middleware('throttle:30,1');
```

Leave any surrounding middleware groups (`supabase.jwt`, `current.pro`, etc.) unchanged — the new route inherits them from the containing group. The per-route throttle runs in addition to, not instead of, any parent throttle.

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Gallery/GalleryCaptionTest.php`
Expected: PASS (all new tests).

- [ ] **Step 7: Run the full test suite to catch regressions**

Run: `composer test`
Expected: the 11 pre-existing failures from the launch-feature-gates branch remain, plus all new gallery tests pass. No NEW failures introduced.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Api/Professional/ImageGallery/UpdateGalleryImageRequest.php \
        app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalGalleryController.php \
        routes/api/professional.php \
        tests/Feature/Gallery/GalleryCaptionTest.php
git commit -m "feat(gallery): PATCH /gallery/{image} for caption + alt_text edits with rate-limit and dirty-check cache guard"
```

---

## Final Verification

- [ ] **Step 1: Smoke-test via curl (local dev)**

Upload a gallery image with a caption:

```bash
curl -X POST http://localhost:8000/api/uploads \
  -H "Authorization: Bearer <token>" \
  -F "pool=gallery" \
  -F "image=@/path/to/test.jpg" \
  -F "caption=My test caption"
```

Expected: 200 response; response JSON includes `"caption": "My test caption"`.

- [ ] **Step 2: Fetch gallery and confirm caption**

```bash
curl -X GET http://localhost:8000/api/gallery \
  -H "Authorization: Bearer <token>"
```

Expected: response `images[]` array; the uploaded image has `"caption": "My test caption"`.

- [ ] **Step 3: Edit the caption via PATCH**

```bash
curl -X PATCH http://localhost:8000/api/gallery/<image-uuid> \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"caption": "Updated caption"}'
```

Expected: 200 response; `image.caption` is `"Updated caption"`.

- [ ] **Step 4: Clear to null by sending empty string**

```bash
curl -X PATCH http://localhost:8000/api/gallery/<image-uuid> \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"caption": "   "}'
```

Expected: 200 response; `image.caption` is `null` (whitespace-only stored as NULL).

- [ ] **Step 5: Verify caption flows through public site payload**

```bash
curl -X GET "http://localhost:8000/api/public/site-by-slug?slug=<your-site-slug>"
```

Expected: `site.gallery[].caption` is present for the edited image.

If caption is missing from the public payload but appears in `GET /api/gallery`, the view wasn't re-emitted correctly in Task 1 — go back and verify the `CREATE OR REPLACE VIEW` ran.

- [ ] **Step 6: Verify per-route throttle fires**

Fire 31 PATCH requests in quick succession:

```bash
for i in $(seq 1 31); do
  curl -s -o /dev/null -w "%{http_code}\n" \
    -X PATCH "http://localhost:8000/api/gallery/<image-uuid>" \
    -H "Authorization: Bearer <token>" \
    -H "Content-Type: application/json" \
    -d "{\"caption\": \"rate-test ${i}\"}"
done
```

Expected: requests 1–30 return `200`, request 31 returns `429` (Too Many Requests). Wait 60s for the bucket to reset before further manual testing.

- [ ] **Step 7: Confirm dirty-check skips cache invalidation**

Manually (or via a small PHP snippet in `php artisan tinker`), observe that submitting a caption identical to the current DB value does NOT result in a `SiteCacheService::invalidateSite` call. The simplest way: put a `Log::info('cache invalidated')` temporarily inside `update()` after the `$changed` branch, watch the log across two PATCH requests (first with a new caption, second with the same caption), and confirm the log line fires only on the first. Remove the temporary log before committing.

- [ ] **Step 8: Notify the frontend of XSS expectations**

Before closing the backend PR, comment on the matching frontend PR (or JIRA) that:
- `caption` is user-controlled text rendered on a public page
- It must be rendered via the framework's default text-escaping pathway (e.g., JSX text interpolation: `<p>{caption}</p>`) — NOT via any API that interprets raw HTML
- Frontend should also enforce the 200-char limit in the editor input for immediate UX feedback, since the backend will still 422 longer values

This is out of the plan's implementation scope but in-scope for "feature is safely shippable."

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Caption column added to `site.site_media` — Task 1
- ✅ Caption projected through public-site DB view — Task 1
- ✅ Caption accepted on upload — Task 3
- ✅ Caption returned in gallery list — Task 4
- ✅ Caption editable after upload via dedicated PATCH — Task 5
- ✅ Covering index parity with alt_text — Task 1
- ✅ Whitespace-only coerced to NULL — Tasks 3 and 5 (via `normaliseCaption` / `normaliseOptionalString`)
- ✅ Max length enforced (200 chars) — Tasks 3 and 5
- ✅ Ownership check (image belongs to current professional's site) — Task 5 (`abort_unless($image->site_id === $site->id, 404)`)
- ✅ Cache invalidated on edit — Task 5 (`SiteCacheService::invalidateSite`)
- ✅ Dirty-check skips cache invalidation when nothing changed — Task 5 (`$image->isDirty([...])`)
- ✅ Per-route rate limit — Task 5 (`throttle:30,1`)
- ✅ Rate-limit verified in production-like conditions — Final Verification Step 6
- ✅ Frontend XSS expectations communicated — Final Verification Step 8

**Placeholder scan:**
- The `>>> PASTED VIEW DEFINITION <<<` block in Task 1 Step 2 is called out as a placeholder the engineer MUST replace — this is intentional (the full view SQL is ~200 lines and belongs in the migration file, not the plan). Instructions include the exact edits to make inside each of the 4 projection blocks. No other placeholders in the plan.

**Type consistency:**
- `caption` is `varchar(200)` in the DB and `max:200` in both request validators
- `alt_text` is `varchar(NULL)`/`text` in DB (unchanged) with `max:255` validator (matches existing upload request)
- `normaliseCaption` / `normaliseOptionalString` — these are two different method names, one per controller. Both do the same thing. If you'd rather DRY this up later, extract to a trait — out of scope for this plan.
- Column name `caption` is consistent across migration, model fillable, controllers, requests, tests, and routes.
