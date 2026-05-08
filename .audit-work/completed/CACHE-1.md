---
item_id: '#CACHE-1'
title: "`invalidateSite` does not bust `:stale` copies for site block caches \u2192\
  \ 2.5 hours of stale blocks after every edit"
source: audit-2026-05-07-caching-foundation.md
tier: P1
effort_estimate: S
completed_at: '2026-05-08T02:48:46+00:00'
mode: overnight
commit_sha: e4489f7
files_touched:
- app/Services/Cache/SiteCacheService.php
test_result: pass
questions_asked: 0
---

# #CACHE-1 — `invalidateSite` does not bust `:stale` copies for site block caches → 2.5 hours of stale blocks after every edit

## Plain English

When a site owner edits a link or section, the system clears a "main" cache copy so visitors see the change right away. But there's also a "backup" copy that lives for 2.5 hours as a safety net. The bug was that saving only cleared the main copy, not the backup — so the very next visitor after an edit would still see the old content via the backup. The fix makes every cache clear also clear its corresponding backup copy.

## Technical Summary

**File changed:** `app/Services/Cache/SiteCacheService.php`

Added a private static helper `bustWithStale(string $key): array` that returns `[$key, "$key:stale"]` — the primary key plus its stale-while-revalidate copy. In `invalidateSite()`, the two `siteBlocks` array entries (for `'links'` and `'sections'`) were replaced with spread calls to this helper, so both the primary key and `:stale` copy are now included in the `Cache::deleteMultiple()` call. This mirrors the existing correct pattern already used for `professionalModel` (lines 804-806) and `siteImagesView` variants (lines 796-797). Only `getSiteLinkBlocks()` currently writes via `rememberLocked` (sections key is in the bust list preemptively); `getPublicSitePayload` uses `Cache::get/put` directly and has no `:stale` copy.

## Decisions Made

- **Added `bustWithStale()` helper instead of inline `:stale` appends:** The asymmetry between keys that correctly bust `:stale` (professionalModel, siteImagesView) and the missed siteBlocks keys was structural — the helper makes the pattern self-documenting and makes future omissions harder. The audit item explicitly suggested this.
- **Did not refactor existing professionalModel / siteImagesView bust patterns to use the helper:** Minimal blast radius — those already work correctly and the helper only needed to fix the new code path.
- **Spread operator in array literal (`...self::bustWithStale(...)`):** Cleaner than two sequential `$keys[] =` pushes; valid since PHP 7.4, well within the PHP 8.2 requirement.

## Notes

Only `getSiteLinkBlocks()` calls `rememberLocked` today; `getSiteSectionBlocks()` does not exist yet. The `siteBlocks($site->id, 'sections')` key is in the bust list preemptively, so busting its `:stale` copy is forward-safe. The `publicSitePayload` key uses a manual single-flight pattern with raw `Cache::get/put` — no `:stale` copy is written there, so no `:stale` bust is needed for it.

## Questions Asked
(none)
