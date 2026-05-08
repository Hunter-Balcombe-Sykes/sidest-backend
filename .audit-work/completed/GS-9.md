---
item_id: '#GS-9'
title: Add ETag / 304 middleware on heavy public reads (mobile + Hydrogen revalidation)
source: audit-2026-05-07-caching-foundation.md
tier: P3
effort_estimate: S
completed_at: '2026-05-08T03:04:22+00:00'
mode: overnight
commit_sha: 643faed
files_touched:
- app/Http/Middleware/AddETagHeaders.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- bootstrap/app.php
- tests/Unit/Middleware/AddETagHeadersTest.php
test_result: pass
questions_asked: 0
---

# #GS-9 — Add ETag / 304 middleware on heavy public reads (mobile + Hydrogen revalidation)

## Plain English

When the Shopify storefront (Hydrogen) or a mobile app asks "has this page changed since I last downloaded it?", the server can now answer "no, same as before" with a tiny 304 response instead of re-sending the full payload. We compute a fingerprint (ETag) of the response body and attach it to the reply. On the next request the client sends that fingerprint back; if it still matches, we skip the body entirely. This only applies to the five public read endpoints that are already publicly cached.

## Technical Summary

- `app/Http/Middleware/AddPublicCacheHeaders.php`: Changed `CACHEABLE_PATH_PREFIXES` from `private` to `public` const so `AddETagHeaders` can reference it without duplicating the list.
- `app/Http/Middleware/AddETagHeaders.php` (new): Middleware that runs after the route handler on cacheable public GETs. Computes an MD5 hash of a canonicalised response body (JSON keys sorted recursively via `sortKeysRecursive()` using `array_is_list()` to distinguish objects from arrays). Sets `ETag: "<hash>"`. If the request carries a matching `If-None-Match` header (supports quoted, comma-list, and weak `W/"..."` formats), mutates the response to 304 with empty body while preserving all headers.
- `bootstrap/app.php`: Imports `AddETagHeaders` and appends it to the `api` middleware group after `AddPublicCacheHeaders`. As the innermost group member it post-processes first, before `AddPublicCacheHeaders` sets Cache-Control — ordering is irrelevant because both middlewares independently check the path allowlist.
- `tests/Unit/Middleware/AddETagHeadersTest.php` (new): 9 unit tests covering ETag generation, JSON key-order stability, 304 on match, 304 with weak validator, no-match 200, POST exclusion, Authorization exclusion, non-cacheable path exclusion, non-2xx exclusion, and all five cacheable prefixes.

## Decisions Made

- **Share path allowlist via `public const`** rather than duplicating it: keeps the two middlewares guaranteed in sync — adding a new cacheable path in `AddPublicCacheHeaders` automatically enables ETags for it without a separate edit.
- **MD5 over SHA1/SHA256**: sufficient entropy for an ETag, faster, produces a shorter (32-char) header value — this is not a security hash.
- **Mutate existing response for 304** (`setStatusCode(304)` + `setContent('')`): preserves all existing headers (Cache-Control, Vary, ETag) without manual copying. Symfony's `Response::prepare()` strips Content-Type and Content-Length for empty status codes during final HTTP send.
- **`array_is_list()` for JSON list vs object distinction**: prevents reordering of ordered lists (e.g. product arrays) while still sorting object keys — a correct, PHP 8.1+ built-in.
- **No external package (`werk365/etagconditionals`)**: the custom implementation is ~80 lines and covers exactly what this codebase needs without an extra dependency.

## Notes

The 304 path coverage assertion (last test) iterates all five `CACHEABLE_PATH_PREFIXES` so it will automatically catch any future prefix additions that forget to be tested.

## Questions Asked
(none)
