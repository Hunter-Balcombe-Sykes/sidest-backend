# Document Upload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-site downloadable file (PDF/JPG/PNG, ≤10 MB) to professional and influencer accounts, shown via a new `documents` section block with browser-native preview and forced-download support.

**Architecture:** Extend `site.site_media` with a new `document` media_type and `documents` pool (max 1 per site). Five new endpoints under `/api/documents` plus a public redirect endpoint that issues R2 presigned URLs with `response-content-disposition=attachment` overrides. View projection adds a singular `document` key to the public-site payload. Section visibility re-uses the existing `SiteMediaObserver` pattern.

**Tech Stack:** PHP 8.2, Laravel 12, PostgreSQL (Supabase), Cloudflare R2 via Laravel's S3 Flysystem adapter (AWS SDK), Pest 4. Presigned URLs via `Storage::disk('media')->temporaryUrl(...)`.

**Spec reference:** `docs/superpowers/specs/2026-04-22-document-upload-design.md`

## Testing Conventions (must-read before writing tests)

This codebase does NOT use Eloquent factories (only `UserFactory`). Three patterns apply:

- **Pattern A — direct validator call.** Test Form Requests via `validateResolved()` (see `tests/Feature/Gallery/GalleryCaptionTest.php`).
- **Pattern B — SQLite in-memory with manual schema.** Per-feature test-case helper boots SQLite and creates minimal tables (see `tests/Feature/FeatureFlags/SectionVisibilityTestCase.php`).
- **Pattern C — route middleware inspection.** For "is this middleware attached to this route," inspect `Route::getRoutes()` rather than issuing real HTTP requests (see `tests/Feature/Gallery/GalleryCaptionTest.php`).

Each task below states which pattern applies.

## File Structure

**New files:**
- `supabase/migrations/20260422010000_add_document_media_type.sql` — column + CHECK constraint update + covering index rebuild + view re-emit
- `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php` — professional-scoped CRUD
- `app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php` — single public endpoint, 302 redirect to presigned URL
- `app/Http/Requests/Api/Professional/Documents/UploadDocumentRequest.php` — validates upload (MIME + size + title + caption)
- `app/Http/Requests/Api/Professional/Documents/UpdateDocumentRequest.php` — validates metadata edit
- `tests/Feature/Documents/DocumentTestCase.php` — SQLite-in-memory schema setup helper
- `tests/Feature/Documents/DocumentUploadTest.php`
- `tests/Feature/Documents/DocumentIndexTest.php`
- `tests/Feature/Documents/DocumentUpdateTest.php`
- `tests/Feature/Documents/DocumentDeleteTest.php`
- `tests/Feature/Documents/PublicDocumentDownloadTest.php`
- `tests/Feature/Documents/DocumentPayloadProjectionTest.php`

**Modified files:**
- `config/sidest.php` — register `documents` in `section_block_types`, `image_pools`, and `account_type_defaults.influencer.allowed_sections`
- `app/Models/Core/Site/SiteMedia.php` — add `POOL_DOCUMENTS`, `MEDIA_TYPE_DOCUMENT` constants; add `original_filename` to `$fillable`
- `app/Observers/Core/SiteMediaObserver.php` — handle the new `documents` pool alongside gallery
- `app/Services/Professional/SectionVisibilityService.php` — add `checkDocumentsRequirements`
- `app/Services/Cache/SiteCacheService.php` — resolve `document.preview_url` in the payload
- `routes/api/professional.php` — register the 4 pro-scoped routes
- `routes/api.php` — register the public download route
- `tests/Pest.php` — add `original_filename TEXT NULL` to SQLite `site_media` schema

## Design Decisions (locked from spec)

- Max 1 document per site (pool cap enforced at controller + config layer)
- PDFs + JPG + PNG only, 10 MB max
- Reuse `site_media.alt_text` as the document title
- Reuse `site_media.caption` for optional description
- New column: `original_filename varchar(255) NULL` for display + disposition filename
- Flat replace (no versioning) — upload soft-deletes previous row AND deletes its R2 bytes synchronously
- `response-content-disposition=attachment; filename="..."` on the download presigned URL (5-minute TTL)
- Fully public access on the download endpoint; only gated by "parent site must be published"
- Professionals + influencers only (brands rejected with 403)

---

## Task 1: Supabase Migration — Column, CHECK Constraint, Covering Index, View Projection

**Files:**
- Create: `supabase/migrations/20260422010000_add_document_media_type.sql`

**Testing pattern:** manual verification via psql after migration applies. No automated test for the migration itself (same precedent as prior migrations in this repo).

- [ ] **Step 1: Read the current view definition**

Open `supabase/migrations/20260421010000_add_caption_to_site_media.sql` (the most recent migration that re-emits `site.public_site_payload`) and locate the full `CREATE OR REPLACE VIEW site.public_site_payload` block. Copy it to your clipboard — you'll paste it verbatim into the new migration in Step 2, then add the new `document` projection.

- [ ] **Step 2: Create the migration file**

Create `supabase/migrations/20260422010000_add_document_media_type.sql`:

```sql
-- Add document media_type to site.site_media and project the new
-- `document` key through the public site payload view.
--
-- Semantics:
--   media_type='document', pool='documents'  — single file per site
--   path = 'documents/{pro_id}/{media_id}/original.{ext}'
--   alt_text  = document title (reused — acts as accessible name)
--   caption   = optional description (reused from gallery captions)
--   original_filename = client-supplied filename, used for display and
--                       for the response-content-disposition override on
--                       the public download presigned URL.
--
-- File types: application/pdf, image/jpeg, image/png
-- Size cap: 10 MB (enforced at the application layer via config)

BEGIN;

-- 1. Add the original_filename column (NULL for images/videos, populated for documents)
ALTER TABLE site.site_media
    ADD COLUMN IF NOT EXISTS original_filename varchar(255) NULL;

-- 2. Update the media_type CHECK constraint to allow 'document'
ALTER TABLE site.site_media
    DROP CONSTRAINT IF EXISTS site_media_media_type_check;

ALTER TABLE site.site_media
    ADD CONSTRAINT site_media_media_type_check
    CHECK (media_type IN ('image', 'video', 'document'));

-- 3. Rebuild the covering index to include original_mime, original_size_bytes,
--    path, and original_filename — so the view can satisfy document row
--    reads from index-only scans.
DROP INDEX IF EXISTS site.site_media_site_active_sort_covering_idx;

CREATE INDEX site_media_site_active_sort_covering_idx
    ON site.site_media (site_id, sort_order)
    INCLUDE (alt_text, caption, media_type, pool, original_mime, original_size_bytes, path, original_filename)
    WHERE deleted_at IS NULL AND is_active = true;

-- 4. Re-emit the public_site_payload view with a new singular `document` key.
--    PASTE THE COMPLETE VIEW DEFINITION FROM 20260421010000 BELOW.
--    Then add the `document` projection immediately after `content_videos`
--    inside the `jsonb_build_object('site', ...)` block, structured as a
--    scalar subquery returning either a single object or NULL.

CREATE OR REPLACE VIEW site.public_site_payload
WITH (security_invoker='on') AS
SELECT
  s.id as site_id,
  s.professional_id,
  s.subdomain,
  jsonb_build_object(
    'site', jsonb_build_object(
      'id', s.id,
      'subdomain', s.subdomain,
      'settings', s.settings,
      'is_published', s.is_published,
      -- [PASTE gallery projection from 20260421010000 verbatim]
      -- [PASTE content_images projection from 20260421010000 verbatim]
      -- [PASTE gallery_videos projection from 20260421010000 verbatim]
      -- [PASTE content_videos projection from 20260421010000 verbatim]
      'document', (
        SELECT jsonb_build_object(
          'id', sm.id,
          'title', sm.alt_text,
          'caption', sm.caption,
          'original_mime', sm.original_mime,
          'original_size_bytes', sm.original_size_bytes,
          'original_filename', sm.original_filename,
          'preview_url', sm.path,
          'created_at', sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'documents'
          AND sm.media_type = 'document'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
        LIMIT 1
      )
    ),
    -- [PASTE remainder of view (professional, theme, links, sections, services) verbatim from 20260421010000]
  ) as payload
FROM site.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN site.themes t ON t.id = s.theme_id
WHERE
  s.is_published = true
  AND p.status = 'active'
  AND p.deleted_at IS NULL;

COMMENT ON VIEW site.public_site_payload IS
    'Complete public site payload with two-flag section visibility (is_enabled + is_active). Includes per-image caption + alt_text, plus singular document reference.';

COMMIT;
```

**CRITICAL:** the 5 `-- [PASTE ...]` comments above are placeholders. Before running the migration, replace each with the literal SQL from `20260421010000_add_caption_to_site_media.sql`. The migration WILL NOT WORK with the placeholder comments left in. Do NOT try to simplify or shorten the view — the column list must stay stable (`site_id`, `professional_id`, `subdomain`, `payload`) or `CREATE OR REPLACE VIEW` will fail.

- [ ] **Step 3: Apply the migration to Supabase**

Use the Supabase MCP tool (or your local `supabase db push` equivalent):

```
mcp__claude_ai_Supabase__apply_migration
  project_id: glncumufgaqcmqhzwrxm
  name: add_document_media_type
  query: <paste the complete migration SQL, with all PASTE placeholders replaced>
```

Expected: `{"success": true}`.

- [ ] **Step 4: Verify column and constraint exist**

Run in psql against the live DB:

```sql
\d site.site_media
-- expected: original_filename column of type character varying(255)
-- expected: site_media_media_type_check CHECK (media_type IN ('image'::text, 'video'::text, 'document'::text))

SELECT indexdef FROM pg_indexes
WHERE schemaname = 'site'
  AND indexname = 'site_media_site_active_sort_covering_idx';
-- expected: INCLUDE list mentions original_filename, original_mime, original_size_bytes, path
```

- [ ] **Step 5: Verify the view projects `document`**

Run in psql:

```sql
SELECT jsonb_path_query_first(payload, '$.site.document') FROM site.public_site_payload LIMIT 1;
-- expected: either a JSON object with the keys above, or null (but the KEY must exist)
```

If the query returns nothing (not null — literally nothing), the view wasn't re-emitted. Go back and verify the `CREATE OR REPLACE VIEW` ran and the `document` key is inside the `jsonb_build_object('site', ...)` block.

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/20260422010000_add_document_media_type.sql
git commit -m "feat(db): add document media_type + public_site_payload projection"
```

---

## Task 2: Config Registration — Pool, Section Type, Allowed Sections

**Files:**
- Modify: `config/sidest.php`
- Test: `tests/Feature/Documents/DocumentTestCase.php` (new — created Step 3 of Task 3; config assertions can live directly in test files)

**Testing pattern:** Pattern A (no DB needed; assertions against loaded config).

- [ ] **Step 1: Create the test directory**

```bash
mkdir -p "/Users/joshuahunter/Herd/Partna/backend/tests/Feature/Documents"
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Documents/DocumentConfigTest.php`:

```php
<?php

it('registers documents as a section_block_type', function () {
    expect(config('sidest.section_block_types'))->toContain('documents');
});

it('registers documents pool with max 1', function () {
    expect(config('sidest.image_pools.documents'))->toMatchArray(['max' => 1]);
});

it('allows documents for influencer (and therefore professional via inheritance)', function () {
    expect(config('sidest.account_type_defaults.influencer.allowed_sections'))
        ->toContain('documents');
});

it('does NOT allow documents for brand accounts', function () {
    expect(config('sidest.account_type_defaults.brand.allowed_sections'))
        ->not->toContain('documents');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentConfigTest.php`
Expected: 3 failures (all 4 assertions except possibly the brand one, which might pass if brand config doesn't include 'documents' anyway).

- [ ] **Step 4: Update `config/sidest.php` — `section_block_types`**

Find the `section_block_types` line (around line 169) and append `'documents'`:

```php
'section_block_types' => ['gallery', 'services', 'shop', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents'],
```

- [ ] **Step 5: Update `config/sidest.php` — `image_pools`**

Find the `image_pools` array. Add a `documents` entry next to the existing pools:

```php
'image_pools' => [
    'gallery' => ['max' => (int) env('SIDEST_GALLERY_IMAGE_MAX', 6)],
    'content' => ['max' => (int) env('SIDEST_CONTENT_IMAGE_MAX', 6)],
    'product' => ['max' => (int) env('SIDEST_PRODUCT_IMAGE_MAX', 5)],
    'brand_gallery' => ['max' => (int) env('SIDEST_BRAND_GALLERY_IMAGE_MAX', 5)],
    'product_custom' => ['max' => (int) env('SIDEST_PRODUCT_CUSTOM_PHOTO_MAX', 1)],
    'documents' => ['max' => 1],
],
```

No env override — the cap is a product decision, not an operator one.

- [ ] **Step 6: Update `config/sidest.php` — `account_type_defaults.influencer.allowed_sections`**

Find the `account_type_defaults.influencer` entry. Its `allowed_sections` currently includes `['shop', 'services', 'gallery']`. Add `'documents'`:

```php
'influencer' => [
    'allowed_sections' => ['shop', 'services', 'gallery', 'documents'],
    'default_sections' => ['shop', 'services', 'gallery'],
    // ... rest unchanged
],
```

Do NOT add `'documents'` to `default_sections` — that would auto-enable the section for new accounts. The pro/influencer opts in by adding the section block explicitly.

The `professional` entry inherits from `influencer` via the `'inherits' => 'influencer'` key, so documents propagates automatically. Its explicit `allowed_sections` list also needs `documents` added for clarity (looking at current config, the professional list is duplicated for readability even though it inherits):

```php
'professional' => [
    'inherits' => 'influencer',
    'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents'],
    'default_sections' => ['shop', 'services', 'gallery'],
    'custom_links_allowed' => true,
],
```

**Do NOT modify** `account_type_defaults.brand.allowed_sections` — brands do not get documents per spec.

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentConfigTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add config/sidest.php tests/Feature/Documents/DocumentConfigTest.php
git commit -m "feat(config): register documents section type, pool, and account-type access"
```

---

## Task 3: Model + SQLite Test-Schema Updates

**Files:**
- Modify: `app/Models/Core/Site/SiteMedia.php`
- Modify: `tests/Pest.php`
- Create: `tests/Feature/Documents/DocumentTestCase.php`
- Test: extension of `DocumentConfigTest.php` plus a model assertion

**Testing pattern:** Pattern A (no DB) for the mass-assignment check; the shared `DocumentTestCase` helper is set up here for later tasks.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Documents/DocumentConfigTest.php`:

```php
it('exposes POOL_DOCUMENTS and MEDIA_TYPE_DOCUMENT constants', function () {
    expect(\App\Models\Core\Site\SiteMedia::POOL_DOCUMENTS)->toBe('documents');
    expect(\App\Models\Core\Site\SiteMedia::MEDIA_TYPE_DOCUMENT)->toBe('document');
});

it('SiteMedia accepts original_filename via mass assignment', function () {
    $media = new \App\Models\Core\Site\SiteMedia([
        'site_id' => (string) \Illuminate\Support\Str::uuid(),
        'pool' => 'documents',
        'media_type' => 'document',
        'path' => 'documents/foo/bar/original.pdf',
        'alt_text' => 'Spring Schedule',
        'caption' => 'Updated monthly',
        'original_mime' => 'application/pdf',
        'original_size_bytes' => 123456,
        'original_filename' => 'schedule-spring-2026.pdf',
        'processing_state' => 'ready',
    ]);

    expect($media->original_filename)->toBe('schedule-spring-2026.pdf');
    expect($media->pool)->toBe('documents');
    expect($media->media_type)->toBe('document');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentConfigTest.php`
Expected: 2 new failures (constant undefined, `original_filename` not mass-assignable).

- [ ] **Step 3: Add constants to `SiteMedia`**

Open `app/Models/Core/Site/SiteMedia.php`. Find the existing `POOL_*` constants block (around lines 23–33). Add:

```php
public const POOL_DOCUMENTS = 'documents';
```

Find the `MEDIA_TYPE_*` constants block (around lines 44–46). Add:

```php
public const MEDIA_TYPE_DOCUMENT = 'document';
```

- [ ] **Step 4: Add `original_filename` to `$fillable`**

In the same file, find the `$fillable` array and insert `'original_filename',` immediately after `'original_mime',`:

```php
protected $fillable = [
    'site_id',
    'pool',
    'path',
    'alt_text',
    'caption',
    'purpose',
    'sort_order',
    'is_active',
    'media_type',
    'processing_state',
    'processing_error',
    'original_mime',
    'original_filename',
    'original_size_bytes',
    'duration_ms',
    'poster_path',
    'product_gid',
];
```

- [ ] **Step 5: Extend the SQLite schema in `tests/Pest.php`**

Open `tests/Pest.php` and find the `site.site_media` CREATE TABLE statement. Add `original_filename TEXT NULL` immediately after the `alt_text` / `caption` / `purpose` lines (relative ordering doesn't matter in SQLite, but keep it near the other `original_*` columns):

```php
$conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
    id TEXT PRIMARY KEY,
    site_id TEXT NULL,
    bucket TEXT NULL,
    path TEXT NULL,
    pool TEXT NULL,
    original_mime TEXT NULL,
    original_filename TEXT NULL,
    original_size_bytes INTEGER NULL,
    media_type TEXT NULL,
    processing_state TEXT NULL,
    processing_error TEXT NULL,
    duration_ms INTEGER NULL,
    poster_path TEXT NULL,
    sort_order INTEGER NULL,
    is_active INTEGER NULL,
    product_gid TEXT NULL,
    alt_text TEXT NULL,
    caption TEXT NULL,
    purpose TEXT NULL,
    created_at TEXT NULL,
    updated_at TEXT NULL,
    deleted_at TEXT NULL
)');
```

- [ ] **Step 6: Create the DocumentTestCase helper**

Create `tests/Feature/Documents/DocumentTestCase.php`:

```php
<?php

namespace Tests\Feature\Documents;

use Illuminate\Support\Facades\DB;

// Boots SQLite in-memory with minimum schemas for document feature tests.
// Follows the pattern from tests/Feature/FeatureFlags/SectionVisibilityTestCase.
class DocumentTestCase
{
    public static function boot(): void
    {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $conn = DB::connection('pgsql');

        foreach (['core', 'site'] as $schema) {
            try {
                $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
            } catch (\Throwable) {
            }
        }

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
            id TEXT PRIMARY KEY,
            handle TEXT,
            display_name TEXT,
            primary_email TEXT,
            auth_user_id TEXT,
            professional_type TEXT DEFAULT "professional",
            status TEXT DEFAULT "active",
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            subdomain TEXT,
            is_published INTEGER DEFAULT 0,
            settings TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
            id TEXT PRIMARY KEY,
            site_id TEXT,
            pool TEXT,
            path TEXT,
            alt_text TEXT,
            caption TEXT,
            original_mime TEXT,
            original_filename TEXT,
            original_size_bytes INTEGER,
            media_type TEXT,
            processing_state TEXT,
            sort_order INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.blocks (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            site_id TEXT,
            block_group TEXT,
            block_type TEXT,
            settings TEXT,
            is_enabled INTEGER DEFAULT 1,
            is_active INTEGER DEFAULT 1,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentConfigTest.php`
Expected: PASS (6 tests total).

- [ ] **Step 8: Commit**

```bash
git add app/Models/Core/Site/SiteMedia.php \
        tests/Pest.php \
        tests/Feature/Documents/DocumentTestCase.php \
        tests/Feature/Documents/DocumentConfigTest.php
git commit -m "feat(model): add document pool + media_type + original_filename to SiteMedia"
```

---

## Task 4: Upload Endpoint — POST /api/documents

**Files:**
- Create: `app/Http/Requests/Api/Professional/Documents/UploadDocumentRequest.php`
- Create: `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php` (stub with `store()` only; other methods added in later tasks)
- Modify: `routes/api/professional.php` — register POST route
- Test: `tests/Feature/Documents/DocumentUploadTest.php`

**Testing pattern:** Pattern A for validator tests + `Storage::fake('media')` for upload integration tests.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Documents/DocumentUploadTest.php`:

```php
<?php

use App\Http\Requests\Api\Professional\Documents\UploadDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function validateUploadDocumentRequest(array $payload, array $files = []): array
{
    $request = Request::create('/test', 'POST', $payload, [], $files);
    $formRequest = UploadDocumentRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

it('UploadDocumentRequest accepts a valid PDF with title', function () {
    Storage::fake('media');
    $result = validateUploadDocumentRequest(
        ['title' => 'My Schedule'],
        ['file' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf')],
    );

    expect($result['errors'] ?? [])->not->toHaveKeys(['file', 'title']);
});

it('UploadDocumentRequest rejects missing title', function () {
    $result = validateUploadDocumentRequest(
        [],
        ['file' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('UploadDocumentRequest rejects file >10 MB', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Huge'],
        // UploadedFile::fake()->create(name, size_in_KB, mime)
        ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('file');
});

it('UploadDocumentRequest rejects disallowed MIME (docx)', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Word Doc'],
        ['file' => UploadedFile::fake()->create('s.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('file');
});

it('UploadDocumentRequest accepts JPG', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Photo schedule'],
        ['file' => UploadedFile::fake()->create('s.jpg', 500, 'image/jpeg')],
    );

    expect($result['errors'] ?? [])->not->toHaveKey('file');
});

it('UploadDocumentRequest accepts PNG', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Photo schedule'],
        ['file' => UploadedFile::fake()->create('s.png', 500, 'image/png')],
    );

    expect($result['errors'] ?? [])->not->toHaveKey('file');
});

it('UploadDocumentRequest rejects title longer than 200 chars', function () {
    $result = validateUploadDocumentRequest(
        ['title' => str_repeat('a', 201)],
        ['file' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf')],
    );

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('UploadDocumentRequest accepts optional caption within limit', function () {
    $result = validateUploadDocumentRequest(
        ['title' => 'Schedule', 'caption' => str_repeat('b', 200)],
        ['file' => UploadedFile::fake()->create('s.pdf', 100, 'application/pdf')],
    );

    expect($result['errors'] ?? [])->not->toHaveKey('caption');
});

it('route POST api/documents is registered and maps to ProfessionalDocumentController@store', function () {
    $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('POST', $r->methods()) && $r->uri() === 'api/documents');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('ProfessionalDocumentController@store');
});

it('route POST api/documents has per-route throttle:10,1 middleware', function () {
    $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('POST', $r->methods()) && $r->uri() === 'api/documents');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:10,1');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentUploadTest.php`
Expected: all 10 tests FAIL — request class doesn't exist, controller doesn't exist, route not registered.

- [ ] **Step 3: Create the request class directory and file**

```bash
mkdir -p "/Users/joshuahunter/Herd/Partna/backend/app/Http/Requests/Api/Professional/Documents"
```

Create `app/Http/Requests/Api/Professional/Documents/UploadDocumentRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Professional\Documents;

use App\Http\Requests\BaseFormRequest;

// V2: Validates document upload — PDF/JPG/PNG only, max 10 MB,
// required title (stored as alt_text on site_media), optional caption.
class UploadDocumentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240', // KB
            ],
            'title' => ['required', 'string', 'max:200'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Document must be a PDF, JPG, or PNG file.',
            'file.max' => 'Document must be smaller than 10 MB.',
        ];
    }
}
```

- [ ] **Step 4: Create the controller with `store()` only**

Create `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Documents\UploadDocumentRequest;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// V2: Document upload (PDF/JPG/PNG, 1 per site). Flat-replace semantics —
// a second upload soft-deletes the existing row and deletes its R2 bytes
// synchronously before creating the new row.
class ProfessionalDocumentController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // Account-type gate: brands are explicitly excluded per spec.
        if ($pro->professional_type === 'brand') {
            return $this->error('Documents section not available for brand accounts.', 403);
        }

        // Double MIME-check via finfo — prevents Content-Type spoofing.
        $file = $request->file('file');
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (! in_array($actualMime, $allowed, true)) {
            return $this->error('Document bytes do not match an accepted file type.', 415);
        }

        $title = trim((string) $request->validated('title'));
        $caption = $this->normaliseOptionalString($request->validated('caption'));
        $originalFilename = substr((string) $file->getClientOriginalName(), 0, 255);

        // Flat replace: delete existing doc row + R2 bytes (if any) atomically
        // with the creation of the new row.
        $media = DB::transaction(function () use ($site, $pro, $file, $actualMime, $title, $caption, $originalFilename) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-documents:{$site->id}"]);
            }

            $existing = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DOCUMENTS)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                // Delete old R2 bytes synchronously (no versioning).
                try {
                    Storage::disk(config('sidest.media_disk'))->delete((string) $existing->path);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete previous document R2 object', [
                        'media_id' => $existing->id,
                        'path' => $existing->path,
                        'error' => $e->getMessage(),
                    ]);
                }
                $existing->delete();
            }

            // Derive extension from actual MIME (not client filename — spoofable).
            $ext = match ($actualMime) {
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            };

            $media = SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DOCUMENTS,
                'path' => '', // set after upload
                'alt_text' => $title,
                'caption' => $caption,
                'sort_order' => 0,
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_DOCUMENT,
                'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                'original_mime' => $actualMime,
                'original_filename' => $originalFilename,
                'original_size_bytes' => $file->getSize(),
            ]);

            // Stream to R2 (not in-memory — matches video upload path for safety).
            $mediaDisk = config('sidest.media_disk');
            $path = "documents/{$pro->id}/{$media->id}/original.{$ext}";
            $stream = fopen($file->getRealPath(), 'rb');
            Storage::disk($mediaDisk)->put($path, $stream, 'public');
            if (is_resource($stream)) {
                fclose($stream);
            }

            $media->update(['path' => $path]);

            return $media;
        });

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['document' => $this->buildDocumentPayload($media)], 201);
    }

    private function buildDocumentPayload(SiteMedia $media): array
    {
        $mediaDisk = config('sidest.media_disk');
        $previewUrl = Storage::disk($mediaDisk)->url((string) $media->path);

        return [
            'id' => $media->id,
            'title' => $media->alt_text,
            'caption' => $media->caption,
            'original_mime' => $media->original_mime,
            'original_size_bytes' => $media->original_size_bytes,
            'original_filename' => $media->original_filename,
            'preview_url' => $previewUrl,
            'download_url' => '/api/public/documents/'.$media->id.'/download',
            'created_at' => $media->created_at,
            'updated_at' => $media->updated_at,
        ];
    }

    private function normaliseOptionalString(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
```

- [ ] **Step 5: Register the route**

Open `routes/api/professional.php`. Find the existing `Route::get('/gallery', ...)` block to locate the imports + middleware group (around line 202).

Add an import at the top of the file alongside the other controller imports:

```php
use App\Http\Controllers\Api\Professional\ProfessionalDocumentController;
```

Inside the same middleware group as other professional routes, add:

```php
// Documents (one file per site)
Route::post('/documents', [ProfessionalDocumentController::class, 'store'])
    ->middleware('throttle:10,1');
```

- [ ] **Step 6: Run tests to verify validator + route tests pass**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentUploadTest.php`
Expected: PASS (10 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Api/Professional/Documents/UploadDocumentRequest.php \
        app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php \
        routes/api/professional.php \
        tests/Feature/Documents/DocumentUploadTest.php
git commit -m "feat(documents): POST /api/documents upload endpoint with flat-replace"
```

---

## Task 5: List Endpoint — GET /api/documents

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php` — add `index()`
- Modify: `routes/api/professional.php` — register GET route
- Test: `tests/Feature/Documents/DocumentIndexTest.php`

**Testing pattern:** Pattern C for route registration; the happy-path "returns null / returns document" is tested via Pattern A-style direct controller invocation with a seeded DB row (using `DocumentTestCase`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Documents/DocumentIndexTest.php`:

```php
<?php

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentTestCase;

beforeEach(function () {
    DocumentTestCase::boot();
});

it('route GET api/documents is registered and maps to ProfessionalDocumentController@index', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/documents');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('ProfessionalDocumentController@index');
});

it('index returns null when no document exists for the site', function () {
    // Direct model check — controller logic returns the same shape.
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'p', 'display_name' => 'P',
        'primary_email' => 'p@example.com', 'status' => 'active',
        'professional_type' => 'professional',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'professional_id' => $proId, 'subdomain' => 'p', 'is_published' => 0,
    ]);

    $exists = SiteMedia::query()
        ->where('site_id', $siteId)
        ->where('pool', SiteMedia::POOL_DOCUMENTS)
        ->where('is_active', true)
        ->whereNull('deleted_at')
        ->exists();

    expect($exists)->toBeFalse();
});

it('index returns a document row when one exists for the site', function () {
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'p', 'display_name' => 'P',
        'primary_email' => 'p@example.com', 'status' => 'active',
        'professional_type' => 'professional',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'professional_id' => $proId, 'subdomain' => 'p', 'is_published' => 0,
    ]);
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'pool' => 'documents',
        'media_type' => 'document',
        'path' => "documents/{$proId}/{$mediaId}/original.pdf",
        'alt_text' => 'Schedule',
        'caption' => null,
        'original_mime' => 'application/pdf',
        'original_filename' => 'schedule.pdf',
        'original_size_bytes' => 100000,
        'processing_state' => 'ready',
        'is_active' => 1,
        'sort_order' => 0,
    ]);

    $row = SiteMedia::query()
        ->where('site_id', $siteId)
        ->where('pool', SiteMedia::POOL_DOCUMENTS)
        ->where('is_active', true)
        ->whereNull('deleted_at')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->alt_text)->toBe('Schedule');
    expect($row->original_filename)->toBe('schedule.pdf');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentIndexTest.php`
Expected: 1 failure ("route registered" — no GET route yet). The two data-seeding tests may pass on their own since they're Eloquent-level checks, but we still add the controller to make the index endpoint actually work.

- [ ] **Step 3: Add `index()` to the controller**

In `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php`, add the method above `store()`:

```php
use Illuminate\Http\Request;

// ... existing imports unchanged

public function index(Request $request): JsonResponse
{
    $pro = $this->currentProfessional($request);
    $pro->loadMissing('site');
    $site = $this->currentSite($pro);

    $media = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DOCUMENTS)
        ->where('is_active', true)
        ->whereNull('deleted_at')
        ->first();

    return $this->success([
        'document' => $media ? $this->buildDocumentPayload($media) : null,
    ]);
}
```

(`Illuminate\Http\Request` may already be imported for other methods — leave it alone if so.)

- [ ] **Step 4: Register the GET route**

Open `routes/api/professional.php`. Add next to the existing `Route::post('/documents', ...)`:

```php
Route::get('/documents', [ProfessionalDocumentController::class, 'index']);
```

(Inherits parent group's `throttle:api` — no per-route throttle needed for a cheap read.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentIndexTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php \
        routes/api/professional.php \
        tests/Feature/Documents/DocumentIndexTest.php
git commit -m "feat(documents): GET /api/documents index endpoint"
```

---

## Task 6: PATCH + DELETE Endpoints

**Files:**
- Create: `app/Http/Requests/Api/Professional/Documents/UpdateDocumentRequest.php`
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php` — add `update()` + `destroy()`
- Modify: `routes/api/professional.php` — register PATCH + DELETE
- Test: `tests/Feature/Documents/DocumentUpdateTest.php`, `tests/Feature/Documents/DocumentDeleteTest.php`

**Testing pattern:** Pattern A (validator direct-call) for rules + Pattern C (route middleware inspection) for throttle attachment.

- [ ] **Step 1: Write the failing test for UpdateDocumentRequest**

Create `tests/Feature/Documents/DocumentUpdateTest.php`:

```php
<?php

use App\Http\Requests\Api\Professional\Documents\UpdateDocumentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

function validateUpdateDocumentRequest(array $payload): array
{
    $request = Request::create('/test', 'PATCH', $payload);
    $formRequest = UpdateDocumentRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['valid' => true, 'errors' => []];
    } catch (ValidationException $e) {
        return ['valid' => false, 'errors' => $e->errors()];
    }
}

it('UpdateDocumentRequest accepts title + caption within limits', function () {
    $result = validateUpdateDocumentRequest([
        'title' => 'New title',
        'caption' => 'New caption',
    ]);

    expect($result['valid'])->toBeTrue();
});

it('UpdateDocumentRequest rejects title longer than 200 chars', function () {
    $result = validateUpdateDocumentRequest(['title' => str_repeat('a', 201)]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('UpdateDocumentRequest rejects caption longer than 200 chars', function () {
    $result = validateUpdateDocumentRequest(['caption' => str_repeat('a', 201)]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toHaveKey('caption');
});

it('UpdateDocumentRequest accepts nullable title and caption', function () {
    $result = validateUpdateDocumentRequest(['title' => null, 'caption' => null]);

    expect($result['valid'])->toBeTrue();
});

it('route PATCH api/documents/{document} maps to update', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('PATCH', $r->methods()) && $r->uri() === 'api/documents/{document}');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('ProfessionalDocumentController@update');
});

it('route PATCH api/documents/{document} has throttle:30,1', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('PATCH', $r->methods()) && $r->uri() === 'api/documents/{document}');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:30,1');
});
```

- [ ] **Step 2: Write the failing test for DELETE**

Create `tests/Feature/Documents/DocumentDeleteTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

it('route DELETE api/documents/{document} maps to destroy', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('DELETE', $r->methods()) && $r->uri() === 'api/documents/{document}');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('ProfessionalDocumentController@destroy');
});

it('route DELETE api/documents/{document} has throttle:30,1', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('DELETE', $r->methods()) && $r->uri() === 'api/documents/{document}');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:30,1');
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentUpdateTest.php tests/Feature/Documents/DocumentDeleteTest.php`
Expected: all new tests FAIL — request class doesn't exist; PATCH and DELETE routes not registered.

- [ ] **Step 4: Create UpdateDocumentRequest**

Create `app/Http/Requests/Api/Professional/Documents/UpdateDocumentRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Professional\Documents;

use App\Http\Requests\BaseFormRequest;

// V2: Validates document metadata edits (title, caption).
// Does NOT accept a file — file replacement goes through POST /api/documents.
class UpdateDocumentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
```

- [ ] **Step 5: Add `update()` and `destroy()` to the controller**

Open `app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php`. Add the import at the top:

```php
use App\Http\Requests\Api\Professional\Documents\UpdateDocumentRequest;
```

Add the two methods after `index()`:

```php
/**
 * Edit document title and/or caption. isDirty-guarded so no-op PATCHes
 * don't churn the public-site cache.
 */
public function update(UpdateDocumentRequest $request, SiteMedia $document): JsonResponse
{
    $pro = $this->currentProfessional($request);
    $pro->loadMissing('site');
    $site = $this->currentSite($pro);

    abort_unless(
        $document->site_id === $site->id
        && $document->pool === SiteMedia::POOL_DOCUMENTS,
        404
    );

    $data = $request->validated();
    $update = [];

    if (array_key_exists('title', $data)) {
        $update['alt_text'] = $this->normaliseOptionalString($data['title']);
    }

    if (array_key_exists('caption', $data)) {
        $update['caption'] = $this->normaliseOptionalString($data['caption']);
    }

    $changed = false;
    if (! empty($update)) {
        $document->fill($update);
        if ($document->isDirty(['alt_text', 'caption'])) {
            $document->save();
            $changed = true;
        }
    }

    if ($changed) {
        app(SiteCacheService::class)->invalidateSite($site);
    }

    return $this->success(['document' => $this->buildDocumentPayload($document->fresh())]);
}

/**
 * Soft-delete the row and synchronously delete the R2 bytes (no
 * versioning, so there's no archival value in keeping bytes around).
 */
public function destroy(Request $request, SiteMedia $document): JsonResponse
{
    $pro = $this->currentProfessional($request);
    $pro->loadMissing('site');
    $site = $this->currentSite($pro);

    abort_unless(
        $document->site_id === $site->id
        && $document->pool === SiteMedia::POOL_DOCUMENTS,
        404
    );

    $mediaDisk = config('sidest.media_disk');
    try {
        Storage::disk($mediaDisk)->delete((string) $document->path);
    } catch (\Throwable $e) {
        Log::warning('Failed to delete document R2 object on destroy', [
            'media_id' => $document->id,
            'path' => $document->path,
            'error' => $e->getMessage(),
        ]);
    }

    $document->delete();

    app(SiteCacheService::class)->invalidateSite($site);

    return $this->success(['deleted' => true]);
}
```

- [ ] **Step 6: Register PATCH + DELETE routes**

Open `routes/api/professional.php`. Next to the existing POST/GET `/documents` routes, add:

```php
Route::patch('/documents/{document}', [ProfessionalDocumentController::class, 'update'])
    ->whereUuid('document')
    ->middleware('throttle:30,1');

Route::delete('/documents/{document}', [ProfessionalDocumentController::class, 'destroy'])
    ->whereUuid('document')
    ->middleware('throttle:30,1');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentUpdateTest.php tests/Feature/Documents/DocumentDeleteTest.php`
Expected: PASS (6 + 2 = 8 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Api/Professional/Documents/UpdateDocumentRequest.php \
        app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php \
        routes/api/professional.php \
        tests/Feature/Documents/DocumentUpdateTest.php \
        tests/Feature/Documents/DocumentDeleteTest.php
git commit -m "feat(documents): PATCH + DELETE /api/documents/{id}"
```

---

## Task 7: Public Download Endpoint — GET /api/public/documents/{id}/download

**Files:**
- Create: `app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php`
- Modify: `routes/api.php` — register the public route
- Test: `tests/Feature/Documents/PublicDocumentDownloadTest.php`

**Testing pattern:** Pattern C for route registration. Integration tests that issue a real presigned URL mock `Storage::fake('media')` — the faked disk doesn't issue real AWS-signed URLs but DOES return a `temporaryUrl()` that includes the query params we can inspect.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Documents/PublicDocumentDownloadTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

it('route GET api/public/documents/{document}/download is registered', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/public/documents/{document}/download');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('PublicDocumentDownloadController');
});

it('route GET api/public/documents/{document}/download has public-site throttle', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/public/documents/{document}/download');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:public-site');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Documents/PublicDocumentDownloadTest.php`
Expected: 2 failures — route not registered, controller not created.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php`:

```php
<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

// V2: Public document download — 302-redirects to an R2 presigned URL
// with a response-content-disposition=attachment override so the browser
// forces a download with the original filename.
class PublicDocumentDownloadController extends ApiController
{
    public function __invoke(SiteMedia $document): RedirectResponse
    {
        // Guard: only serve documents that are active, not soft-deleted,
        // from published sites, and actually in the documents pool.
        abort_unless(
            $document->pool === SiteMedia::POOL_DOCUMENTS
            && $document->is_active
            && $document->deleted_at === null,
            404
        );

        $site = Site::query()->find($document->site_id);
        abort_unless($site && $site->is_published, 404);

        $mediaDisk = config('sidest.media_disk');
        $filename = $document->original_filename ?: 'document';

        $presignedUrl = Storage::disk($mediaDisk)->temporaryUrl(
            (string) $document->path,
            now()->addMinutes(5),
            [
                'ResponseContentDisposition' => 'attachment; filename="'.$this->sanitiseFilename($filename).'"',
            ]
        );

        return redirect()->away($presignedUrl);
    }

    /**
     * Strip quote / newline characters that would break the Content-Disposition
     * header. Keeps alphanumerics, dots, dashes, underscores, spaces.
     */
    private function sanitiseFilename(string $name): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9._\- ]/', '', $name);

        return $cleaned !== '' ? $cleaned : 'document';
    }
}
```

- [ ] **Step 4: Register the public route**

Open `routes/api.php`. Find the block with other `Route::get('/public/...)` endpoints. Add:

```php
use App\Http\Controllers\Api\PublicSite\PublicDocumentDownloadController;

// ... existing imports

Route::get('/public/documents/{document}/download', PublicDocumentDownloadController::class)
    ->whereUuid('document')
    ->middleware('throttle:public-site');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Documents/PublicDocumentDownloadTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php \
        routes/api.php \
        tests/Feature/Documents/PublicDocumentDownloadTest.php
git commit -m "feat(documents): public download endpoint with presigned URL redirect"
```

---

## Task 8: Public Site Payload URL Resolution

**Files:**
- Modify: `app/Services/Cache/SiteCacheService.php` — resolve `document.preview_url` from storage path to full CDN URL
- Test: `tests/Feature/Documents/DocumentPayloadProjectionTest.php`

**Testing pattern:** Pattern A — unit-level test on the resolve method, passing in a stub payload array.

- [ ] **Step 1: Find the existing URL-resolution method**

Open `app/Services/Cache/SiteCacheService.php`. There's an existing method around line 610-640 (per earlier exploration) that iterates over `gallery`, `content_images`, `gallery_videos`, `content_videos` and resolves the `variants` dict. We need to add a parallel resolution for the singular `document` key.

Look for a method named something like `resolveMediaUrlsInSite` or similar that currently handles the four media arrays. Grep:

```bash
grep -n "gallery.*content_images\|resolveMediaUrl\|variantUrls\|->url(" "app/Services/Cache/SiteCacheService.php" | head -20
```

You'll find the method. It receives the `$site` array and mutates it in place.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Documents/DocumentPayloadProjectionTest.php`:

```php
<?php

use App\Services\Cache\SiteCacheService;

it('resolves document.preview_url from path to full CDN URL', function () {
    $service = app(SiteCacheService::class);
    $method = (new ReflectionClass($service))->getMethod('resolveMediaUrlsInSite');
    // The actual method name may differ — grep the file to find the public
    // entry point (or private method called from hydrateSiteWithCachedMedia).
    $method->setAccessible(true);

    $site = [
        'gallery' => [],
        'content_images' => [],
        'gallery_videos' => [],
        'content_videos' => [],
        'document' => [
            'id' => 'doc-1',
            'title' => 'Schedule',
            'preview_url' => 'documents/pro-1/media-1/original.pdf',
        ],
    ];

    $resolved = $method->invoke($service, $site);

    expect($resolved['document']['preview_url'])->toStartWith('http');
    expect($resolved['document']['preview_url'])->toContain('documents/pro-1/media-1/original.pdf');
});

it('leaves document as null when no document exists', function () {
    $service = app(SiteCacheService::class);
    $method = (new ReflectionClass($service))->getMethod('resolveMediaUrlsInSite');
    $method->setAccessible(true);

    $site = [
        'gallery' => [],
        'content_images' => [],
        'gallery_videos' => [],
        'content_videos' => [],
        'document' => null,
    ];

    $resolved = $method->invoke($service, $site);

    expect($resolved['document'])->toBeNull();
});
```

**If the actual method name differs** from `resolveMediaUrlsInSite`, update both tests accordingly — use the name found by grep in Step 1.

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentPayloadProjectionTest.php`
Expected: both tests FAIL — the existing method only handles the 4 media arrays, not the `document` key.

- [ ] **Step 4: Extend the URL-resolution method**

In `app/Services/Cache/SiteCacheService.php`, locate the method identified in Step 1. It currently resolves URLs for the four media arrays by iterating and calling `Storage::disk($mediaDisk)->url(...)` or similar on each item's `path`/`variants` fields.

Add a parallel block AFTER the existing four-array loop:

```php
// Resolve the singular document preview_url (null if no document exists).
if (isset($site['document']) && is_array($site['document']) && ! empty($site['document']['preview_url'])) {
    $rawPath = (string) $site['document']['preview_url'];
    $site['document']['preview_url'] = Storage::disk($mediaDisk)->url($rawPath);
}
```

Use whatever local variable the existing code uses for the disk name (`$mediaDisk`, `$disk`, etc.) — match the existing pattern exactly. Do NOT re-read config each iteration.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentPayloadProjectionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Cache/SiteCacheService.php \
        tests/Feature/Documents/DocumentPayloadProjectionTest.php
git commit -m "feat(documents): resolve document preview_url in public site payload"
```

---

## Task 9: Section Visibility + Observer Integration

**Files:**
- Modify: `app/Services/Professional/SectionVisibilityService.php` — add `checkDocumentsRequirements`
- Modify: `app/Observers/Core/SiteMediaObserver.php` — handle documents pool alongside gallery
- Test: extend `tests/Feature/Documents/DocumentConfigTest.php` (or new file)

**Testing pattern:** Pattern B — uses `DocumentTestCase::boot()` for the visibility check against real DB rows.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Documents/DocumentConfigTest.php`:

```php
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentTestCase;

it('SectionVisibilityService rejects documents section when no document is uploaded', function () {
    DocumentTestCase::boot();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'p', 'display_name' => 'P',
        'primary_email' => 'p@example.com', 'status' => 'active',
        'professional_type' => 'professional',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'professional_id' => $proId, 'subdomain' => 'p', 'is_published' => 0,
    ]);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'documents');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('document');
});

it('SectionVisibilityService allows documents section when a document exists', function () {
    DocumentTestCase::boot();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'p', 'display_name' => 'P',
        'primary_email' => 'p@example.com', 'status' => 'active',
        'professional_type' => 'professional',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'professional_id' => $proId, 'subdomain' => 'p', 'is_published' => 0,
    ]);
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'pool' => 'documents',
        'media_type' => 'document',
        'path' => 'documents/.../original.pdf',
        'alt_text' => 'Schedule',
        'original_mime' => 'application/pdf',
        'processing_state' => 'ready',
        'is_active' => 1,
    ]);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'documents');

    expect($canBeVisible)->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentConfigTest.php`
Expected: 2 new failures — the service returns the default `[true, null]` for unrecognised block types today.

- [ ] **Step 3: Add the `documents` case to `SectionVisibilityService`**

Open `app/Services/Professional/SectionVisibilityService.php`. Find the `checkVisibilityRequirements` match expression. Add the documents case:

```php
return match ($blockType) {
    'gallery' => $this->checkGalleryRequirements($siteId),
    'booking' => $this->checkBookingRequirements($professionalId),
    'services' => $this->checkServicesRequirements($professionalId),
    'documents' => $this->checkDocumentsRequirements($siteId),
    default => [true, null],
};
```

Add the private method at the bottom of the class (next to the other `check*Requirements` methods):

```php
private function checkDocumentsRequirements(string $siteId): array
{
    $hasDocument = SiteMedia::query()
        ->where('site_id', $siteId)
        ->where('pool', SiteMedia::POOL_DOCUMENTS)
        ->where('is_active', true)
        ->whereNull('deleted_at')
        ->exists();

    if (! $hasDocument) {
        return [false, 'Documents section requires an uploaded document.'];
    }

    return [true, null];
}
```

(`SiteMedia` is already imported at the top of the file.)

- [ ] **Step 4: Extend `SiteMediaObserver` to handle the documents pool**

Open `app/Observers/Core/SiteMediaObserver.php`. The observer currently only reacts to `POOL_GALLERY`. Update each of the three event methods (`saved`, `deleted`, `restored`) to also fire for `POOL_DOCUMENTS`:

```php
public function saved(SiteMedia $media): void
{
    if ($media->pool === SiteMedia::POOL_GALLERY) {
        $this->reevaluateSection($media, 'gallery');
    } elseif ($media->pool === SiteMedia::POOL_DOCUMENTS) {
        $this->reevaluateSection($media, 'documents');
    }
}

public function deleted(SiteMedia $media): void
{
    if ($media->pool === SiteMedia::POOL_GALLERY) {
        $this->reevaluateSection($media, 'gallery');
    } elseif ($media->pool === SiteMedia::POOL_DOCUMENTS) {
        $this->reevaluateSection($media, 'documents');
    }
}

public function restored(SiteMedia $media): void
{
    if ($media->pool === SiteMedia::POOL_GALLERY) {
        $this->reevaluateSection($media, 'gallery');
    } elseif ($media->pool === SiteMedia::POOL_DOCUMENTS) {
        $this->reevaluateSection($media, 'documents');
    }
}
```

Rename the existing private `reevaluateGallery` method to `reevaluateSection` and make it take the block type as a parameter:

```php
private function reevaluateSection(SiteMedia $media, string $blockType): void
{
    try {
        $site = Site::query()->find($media->site_id);
        if (! $site || ! $site->professional_id) {
            return;
        }

        $this->visibilityService->reevaluateEnabled(
            (string) $site->professional_id,
            (string) $media->site_id,
            $blockType
        );
    } catch (\Throwable $e) {
        Log::warning('Section visibility reevaluation failed', [
            'site_media_id' => $media->id,
            'site_id' => $media->site_id,
            'block_type' => $blockType,
            'message' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Documents/DocumentConfigTest.php`
Expected: PASS (8 tests total).

- [ ] **Step 6: Run full test suite to check for regressions**

Run: `composer test`
Expected: all prior tests still pass. If any `SiteMediaObserver` tests exist elsewhere, they should still pass because the method was renamed but the behaviour for the gallery pool is identical.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Professional/SectionVisibilityService.php \
        app/Observers/Core/SiteMediaObserver.php \
        tests/Feature/Documents/DocumentConfigTest.php
git commit -m "feat(documents): section visibility gate + observer reevaluation"
```

---

## Final Verification

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: the 11 pre-existing failures from earlier work remain unchanged. All new document tests pass. Zero new regressions.

- [ ] **Step 2: Manual smoke-test — upload**

```bash
curl -X POST http://localhost:8000/api/documents \
  -H "Authorization: Bearer <pro-token>" \
  -F "file=@/path/to/schedule.pdf" \
  -F "title=My Education Schedule"
```

Expected: 201 Created; response includes `document.preview_url` (R2 CDN URL), `document.download_url` (points at `/api/public/documents/<id>/download`), `document.original_filename` = `schedule.pdf`.

- [ ] **Step 3: Manual smoke-test — flat replace**

```bash
curl -X POST http://localhost:8000/api/documents \
  -H "Authorization: Bearer <pro-token>" \
  -F "file=@/path/to/schedule-v2.pdf" \
  -F "title=My Updated Schedule"
```

Expected: 201 Created; response shows the new document. Hitting `GET /api/documents` returns only the new one. Checking the R2 bucket shows the old `original.pdf` is gone.

- [ ] **Step 4: Manual smoke-test — brand rejection**

Use a brand-type pro's token:

```bash
curl -X POST http://localhost:8000/api/documents \
  -H "Authorization: Bearer <brand-token>" \
  -F "file=@/path/to/anything.pdf" \
  -F "title=Brand Doc"
```

Expected: 403 Forbidden with the account-type message.

- [ ] **Step 5: Manual smoke-test — public download**

Fetch the site's bootstrap payload and confirm `site.document` is present. Then:

```bash
curl -I http://localhost:8000/api/public/documents/<id>/download
```

Expected: `302 Found` with `Location:` header pointing at a URL containing `response-content-disposition=attachment` and the original filename.

- [ ] **Step 6: Manual smoke-test — section visibility**

Create a `documents` section block (via whatever existing upsert endpoint, e.g. `PUT /api/sections/documents`). Without a document uploaded: it should be `is_enabled=false`. Upload a document, refetch the block: `is_enabled=true`. Delete the document, refetch: `is_enabled=false`.

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Extends `site_media` with `media_type='document'` — Task 1
- ✅ New `documents` pool, max 1, enforced at controller layer — Tasks 2 and 4
- ✅ `original_filename` column added + included in covering index — Task 1
- ✅ `POST /api/documents` with flat-replace + double MIME check — Task 4
- ✅ `GET /api/documents` returns singular document (or null) — Task 5
- ✅ `PATCH /api/documents/{id}` with isDirty cache guard — Task 6
- ✅ `DELETE /api/documents/{id}` with synchronous R2 cleanup — Task 6
- ✅ Public download endpoint with presigned URL + disposition override — Task 7
- ✅ View projection adds `document` key (nullable) — Task 1
- ✅ URL resolution in `SiteCacheService` for `document.preview_url` — Task 8
- ✅ Section visibility `checkDocumentsRequirements` — Task 9
- ✅ Observer reevaluation on document save/delete/restore — Task 9
- ✅ Account-type gate rejects brands with 403 — Task 4
- ✅ Rate limits: 10,1 on upload; 30,1 on PATCH/DELETE; inherits public-site on download — Tasks 4, 6, 7
- ✅ Whitespace-only title/caption coerced to NULL — Tasks 4 and 6

**Placeholder scan:** one intentional placeholder exists in Task 1 Step 2 (`-- [PASTE ...]` comments for the four media projections). The task explicitly flags this as a required manual step with clear instructions for what to paste and from where. No other placeholders in the plan.

**Type consistency:**
- `SiteMedia::POOL_DOCUMENTS` and `SiteMedia::MEDIA_TYPE_DOCUMENT` used consistently across config, model, controllers, observer, service, and tests.
- The payload shape (id, title, caption, original_mime, original_size_bytes, original_filename, preview_url, download_url, created_at, updated_at) is emitted identically from the `buildDocumentPayload` helper in every endpoint that returns a document.
- The HTTP response shape `{ document: {...} | null }` is consistent between POST (201), GET, and PATCH (200).
- Route URIs `api/documents`, `api/documents/{document}`, `api/public/documents/{document}/download` consistent across controllers, tests, and the download_url field in payloads.
