# Document Upload — Design Spec

**Date:** 2026-04-22
**Author:** brainstorming session with Josh
**Status:** Approved — ready to convert to implementation plan

## Goal

Let professional and influencer accounts attach a single downloadable file (PDF, JPG, or PNG, ≤10 MB) to their public site, rendered via a dedicated `documents` section block with in-browser preview and force-download support.

## Scope

- **In scope:** one document per site, fully public access, dedicated section block, preview + download UX, account-type gate (professional + influencer).
- **Out of scope:** brand accounts, multiple documents per site, version history, gated/paywalled downloads, server-side virus scanning, active-content stripping from PDFs, a "recently deleted" recovery UI.

## Decisions (Locked)

| Decision | Choice | Rationale |
|---|---|---|
| Ownership scope | One document per `site_id` | Matches every other upload in the codebase; keyed for RLS, cache invalidation, and ownership checks. |
| File types | PDF + JPG + PNG | Browser-native preview for all three; broad enough to cover "education schedule," "pricing sheet," and camera-direct uploads. |
| Display surface | Dedicated `documents` section block | Natural room for title + description; scales cleanly if we later allow multiple documents. |
| Metadata | Section title + per-document title | Section header groups, document title describes the file. |
| Replace behaviour | Flat replace (no version history) | Matches "single file slot" mental model; simplest data path; 30-day soft-delete retention is a staff recovery tool, not user-facing undo. |
| Max file size | 10 MB | Consistent with existing image pool cap; adequate for realistic document use cases. |
| Access | Fully public | Marketing asset; no gating. |
| Account types | Professionals + influencers | Not brands — brands have Shopify for product assets. |
| Implementation approach | Dedicated `/api/documents` endpoint family (Approach 2) | Documents behave genuinely differently from images/videos; forcing them into the shared upload endpoint would muddy validation. |

## Architecture

### Storage — extend `site.site_media`

One new row per document. Fields used:

| Column | Value for documents |
|---|---|
| `site_id` | The owning site |
| `pool` | `'documents'` (new value) |
| `media_type` | `'document'` (new; added to CHECK constraint) |
| `path` | R2 key: `documents/{professional_id}/{site_media_id}/original.{ext}` |
| `alt_text` | Document title (reused — acts as the accessible name) |
| `caption` | Optional description (reused from gallery caption work) |
| `original_mime` | `application/pdf` / `image/jpeg` / `image/png` |
| `original_size_bytes` | Integer |
| `original_filename` | **NEW column** — `varchar(255) NULL`, stores the uploaded client filename for display + presigned-URL disposition |
| `processing_state` | `'ready'` immediately (no processing pipeline) |
| `duration_ms`, `poster_path`, `processing_error` | NULL, unused |

### Section block registration

- `config/sidest.php` — add `'documents'` to `section_block_types`
- `config/sidest.php` — add `'documents'` to `account_type_defaults.influencer.allowed_sections` (and it's inherited by `professional`)
- **Not** added to `brand.allowed_sections`
- Add `image_pools.documents => ['max' => 1]`

### Section block settings (JSONB)

The `documents` section block's `settings` column holds:
- `title` — visitor-facing section header (e.g. "Resources", "Downloads")

The *document's own title* lives on the `site_media` row (`alt_text`), not on the block.

### Public site payload shape

The `site.public_site_payload` view gets a new singular key:

```json
{
  "site": {
    "gallery": [...],
    "content_images": [...],
    "gallery_videos": [...],
    "content_videos": [...],
    "document": {
      "id": "uuid",
      "title": "Education Schedule — Spring 2026",
      "caption": "Updated monthly",
      "original_mime": "application/pdf",
      "original_size_bytes": 1245789,
      "preview_url": "https://media.sidest.co/documents/.../original.pdf",
      "created_at": "..."
    }
  }
}
```

Singular (not array) because max cardinality is 1. Null when no document exists. `download_url` is **not** in the payload — the frontend builds it from `document.id` against the backend-served download endpoint.

### Section visibility gate

`SectionVisibilityService::checkVisibilityRequirements` gets a new case:

```php
'documents' => $this->checkDocumentsRequirements($professionalId, $siteId),
```

The `documents` section may only be published when at least one active, non-deleted document exists on the site. Evaluated via a new `SiteMediaObserver` that triggers `SectionVisibilityService::reevaluateEnabled($pro_id, $site_id, 'documents')` on document `saved`/`deleted`/`restored` events — same pattern as `ServiceObserver`.

## Endpoints

All under `/api/professional/...` (JWT-authenticated) unless noted.

### `POST /api/documents`

Upload a new document. If one already exists, soft-delete the existing row, delete its R2 bytes, and create a new row (flat replace).

**Form fields (multipart):**
- `file` — required, MIME-allowlisted (PDF/JPG/PNG), max 10 MB
- `title` — required, string, max 200 chars → `site_media.alt_text`
- `caption` — optional, string, max 200 chars (trimmed, empty→NULL)

**Validation (double-check):**
1. Laravel `mimes:pdf,jpg,jpeg,png` rule (trusts Content-Type header — spoofable)
2. `finfo_file()` on temp file (sniffs actual bytes — not spoofable)

Both must pass.

**Returns:**
- `201 Created` with document payload
- `413 Payload Too Large` if >10 MB
- `415 Unsupported Media Type` if MIME fails either check
- `422` on other validation errors
- `403` if account type is `'brand'`

**Rate limit:** `throttle:10,1`

### `GET /api/documents`

**Returns:**
```json
{ "document": { ... } | null }
```

Returns `null` if no active document exists for the current pro's site.

**Rate limit:** inherits parent `throttle:api`.

### `PATCH /api/documents/{id}`

Edit title and/or caption. Does NOT accept a file — replace-via-new-file is the upload endpoint's job.

**Body (JSON):**
- `title` — optional, string, max 200 chars, nullable
- `caption` — optional, string, max 200 chars, nullable

**Behaviour:**
- Ownership check — 404 if `document.site_id !== current_site.id`
- Whitespace trim + empty-to-NULL coercion
- `isDirty(['alt_text', 'caption'])` guard — skip save + cache invalidation if nothing changed

**Returns:** `200 OK` with updated document payload.

**Rate limit:** `throttle:30,1`

### `DELETE /api/documents/{id}`

Soft-delete the row AND synchronously delete the R2 bytes. (Retention is for row-level staff recovery only — R2 storage is cleaned immediately to avoid drift.)

**Behaviour:**
- Ownership check — 404 if cross-site
- Invalidate site cache

**Returns:** `200 OK` with `{ "deleted": true }`

**Rate limit:** `throttle:30,1`

### `GET /api/public/documents/{id}/download`

Public, unauthenticated. Issues a short-TTL R2 presigned URL with a `response-content-disposition=attachment; filename="..."` override, then 302-redirects the browser.

**Behaviour:**
1. Look up the document; 404 if not found, soft-deleted, or its site is unpublished
2. Generate presigned URL (TTL 5 min) with `response-content-disposition=attachment; filename="{original_filename}"`
3. Return `302 Found` with `Location:` = presigned URL

**Why redirect instead of streaming:** PHP streaming 10 MB PDFs through web workers wastes memory + timeout budget. The presigned-URL redirect offloads transfer to Cloudflare's edge.

**Rate limit:** inherits `throttle:public-site`.

## Response payload shape

Every endpoint that returns a document returns this shape:

```json
{
  "id": "uuid",
  "title": "Education Schedule — Spring 2026",
  "caption": "Updated monthly",
  "original_mime": "application/pdf",
  "original_size_bytes": 1245789,
  "preview_url": "https://media.sidest.co/documents/.../original.pdf",
  "download_url": "/api/public/documents/{id}/download",
  "created_at": "2026-04-22T10:30:00Z",
  "updated_at": "2026-04-22T10:30:00Z"
}
```

## Data flow — happy path

### Upload
1. Pro opens dashboard documents editor, picks file
2. Frontend POSTs `file` + `title` to `/api/documents`
3. Backend validates → soft-deletes any existing document row + R2 bytes → creates new row → streams file to R2 → invalidates site cache
4. Backend returns `201` with payload
5. Frontend updates local state; pro sees new document card in editor

### Visitor preview
1. Visitor loads pro's site; bootstrap payload includes `site.document` object
2. Frontend renders section: `<h2>{section.settings.title}</h2>` + card with file icon, `{document.title}`, `{document.caption}`
3. Visitor clicks card → frontend opens a modal with `<iframe src={document.preview_url}>` (PDF) or `<img src={document.preview_url}>` (image)

### Visitor download
1. Modal has a "Download" button
2. Button is `<a href="/api/public/documents/{id}/download" target="_blank">Download</a>`
3. Browser hits backend → 302 to R2 presigned URL → R2 serves with `Content-Disposition: attachment; filename="..."` → browser saves the file with its original filename

## Error handling

| Scenario | Response |
|---|---|
| Upload >10 MB | `413 Payload Too Large` |
| Upload with wrong MIME | `415 Unsupported Media Type` |
| Upload missing `title` | `422 Unprocessable Content` |
| Upload from brand account | `403 Forbidden` with `"Documents section not available for brand accounts"` |
| PATCH on another pro's document | `404 Not Found` (deliberate, avoids leaking existence) |
| Public download on unpublished site | `404 Not Found` |
| Public download of soft-deleted document | `404 Not Found` |
| R2 write failure during upload | Roll back the DB row, return `500` with generic error |

## Testing strategy

Six test files under `tests/Feature/Documents/`:
- `DocumentUploadTest.php` — upload happy path, MIME/size validation, flat-replace behaviour, account-type gate, R2 path correctness (via `Storage::fake('media')`)
- `DocumentIndexTest.php` — shape of null / non-null response, ownership scope
- `DocumentUpdateTest.php` — title/caption edits, whitespace normalisation, isDirty cache-skip, cross-site 404
- `DocumentDeleteTest.php` — soft-delete + R2 delete, cross-site 404, cache invalidation
- `PublicDocumentDownloadTest.php` — presigned URL response, 404 on unpublished/deleted, throttle route-attachment
- `DocumentPayloadProjectionTest.php` — view returns `document` key (null + non-null paths)

**New test helper:** `DocumentTestCase.php` — SQLite in-memory schema setup following `SectionVisibilityTestCase.php` pattern.

**`tests/Pest.php` schema additions:** `original_filename TEXT NULL` on the `site.site_media` mock schema.

## Security posture

| Concern | Mitigation |
|---|---|
| MIME spoofing | Double-check: Laravel `mimes:` rule + `finfo_file()` byte sniffing |
| Path traversal via filename | R2 path always `original.{ext}` — no user-controlled strings |
| Cross-site tampering | All endpoints check `document.site_id === current_site.id` |
| Public access to drafts | Download endpoint verifies `is_published=true` |
| Download URL abuse | Presigned URL TTL of 5 minutes; `throttle:public-site` on the generator |
| PDF JavaScript | Accepted risk — browsers sandbox PDF JS heavily; deferred for v1 |
| Upload abuse | `throttle:10,1` on POST — 10 per minute per pro |
| Cache churn on no-op edits | `isDirty` guard on PATCH skips invalidation |
| Virus / malware uploads | Accepted risk v1; same posture as existing image uploads. ClamAV integration deferred. |

## Scalability posture

| Concern | Mitigation |
|---|---|
| Storage cost | One document per site, max 10 MB — steady-state storage is tiny |
| R2 bandwidth on preview | Cached at CDN edge; no backend involvement |
| R2 bandwidth on download | Presigned-URL redirect; no PHP streaming |
| Orphaned R2 bytes after delete | Synchronous R2 delete on every DB soft-delete — no drift |
| Payload size impact | ~200 bytes added to site payload per site; negligible |
| Covering index parity | Extend existing covering index to INCLUDE new columns |
| Public site view recompile cost | One-time migration cost; negligible |

## Deferred (explicit tech debt)

- Virus scanning (ClamAV)
- PDF active-content sanitisation (ghostscript re-encoding)
- Multiple documents per site (would require repromoting `document` to `documents` array)
- Version history / revert (Flavor 2/3 from the brainstorm)
- Brand account access to the documents section
- Lead-gated downloads (CAPTCHA / email unlock)
- Dashboard UI "recently deleted" recovery surface
- Download analytics (count clicks on `/api/public/documents/{id}/download`)

## Open questions

None. All design decisions are locked.
