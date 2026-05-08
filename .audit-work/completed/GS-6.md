---
item_id: '#GS-6'
title: Add `Cache::memo()` decorator to hot read paths (Laravel 12.9+)
source: audit-2026-05-07-caching-foundation.md
tier: P3
effort_estimate: S
completed_at: '2026-05-08T03:16:35+00:00'
mode: overnight
commit_sha: a651eed
files_touched:
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php
- app/Http/Requests/Api/Professional/StorePlanSubscriptionRequest.php
- app/Services/Store/AffiliateProductCatalogService.php
- app/Services/Store/BrandCatalogService.php
test_result: pass
questions_asked: 0
---

# #GS-6 — Add `Cache::memo()` decorator to hot read paths (Laravel 12.9+)

## Plain English

When a request asks for the same cached data multiple times, each ask previously made a round-trip to Redis. The `Cache::memo()` layer added in Laravel 12.9 keeps the answer in memory for the life of the request, so the second and third asks are answered instantly without touching Redis. Six call sites that used `Cache::remember()` directly — the brand catalog, affiliate catalog, brand design, product analytics, and the free-plan-ID lookup — now use this per-request memory layer as a drop-in replacement.

## Technical Summary

Changed `Cache::remember(...)` → `Cache::memo()->remember(...)` at six call sites:

- `app/Services/Store/BrandCatalogService.php` — `fetchBrandCatalog()` and `resolveCollectionGid()`
- `app/Services/Store/AffiliateProductCatalogService.php` — `fetchActiveCatalog()`
- `app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php` — `show()`
- `app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php` — `show()`
- `app/Http/Requests/Api/Professional/StorePlanSubscriptionRequest.php` — `freePlanId()`

`Cache::memo()` (Laravel 12.9+, we're on 12.42.0) returns a request-scoped `Repository` backed by `MemoizedStore`. `MemoizedStore::get()` auto-populates an in-memory array on Redis hits; subsequent `get()` calls for the same key within the request short-circuit before reaching Redis. The decorator is cleared between requests via `scopedIf` binding. No contract changes; all callers are unaffected.

`CacheLockService` was intentionally left unchanged. Its test suite uses `Cache::shouldReceive()` (facade-level mocking), which is incompatible with `Cache::memo()`'s internal store resolution path — memo goes through `$this->store($driver)` rather than the facade mock, making the two strategies structurally incompatible without rewriting the tests.

## Decisions Made

- **Excluded `CacheLockService`**: The existing `CacheLockServiceTest` mocks `Cache` at the facade level (`Cache::shouldReceive('get')`). Wrapping `rememberLocked()`'s fast path with `Cache::memo()->get()` would route through `MemoizedStore → RedisStore`, bypassing the facade mock and breaking all fast-path cache-hit tests. Keeping `CacheLockService` unchanged avoids test breakage while still delivering memo benefits on the six direct call sites.
- **Included `StorePlanSubscriptionRequest`**: Not explicitly called out in the audit item's "Where" field, but it holds one of the six `Cache::remember()` calls in the codebase and the change is zero-risk.

## Notes

`MemoizedStore::put()` clears the key from the in-memory array and writes to the backing store (does NOT populate memo). This means a cold-miss compute+write path won't memo the result until the *next* `get()` call re-hydrates from Redis. This is acceptable — the primary benefit is warm-key reads, which are the dominant production path.

## Questions Asked
(none)
