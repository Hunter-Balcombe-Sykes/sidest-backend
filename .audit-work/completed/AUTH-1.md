---
item_id: '#AUTH-1'
title: VerifyEmbeddedApiKey silently bypasses auth when `embedded.api_key` config
  is empty
source: pilot-stage-3.md
tier: P0
effort_estimate: S
completed_at: '2026-05-08T02:01:38+00:00'
mode: overnight
commit_sha: 56d0f6b
files_touched:
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
test_result: pass
questions_asked: 0
---

# #AUTH-1 — VerifyEmbeddedApiKey silently bypasses auth when `embedded.api_key` config is empty

## Plain English

The embedded Shopify app door had a broken lock: if the secret passcode (SIDEST_EMBEDDED_API_KEY) was never set in production, the lock check was skipped entirely and anyone could walk in. The fix makes the door throw an alarm instead of opening when no passcode is configured — except during local development and automated testing, where skipping is intentional. This matches exactly how the Hydrogen door was already protected.

## Technical Summary

**File changed:** `app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php`

The original code used `if ($expected !== '')` to gate key validation, meaning an empty config value caused the entire auth block to be bypassed. Replaced with the fail-closed pattern from `VerifyHydrogenApiKey`: check `$expected === ''` first; if so, allow pass-through only in `local`/`testing` environments via `app()->environment()`; otherwise throw `\RuntimeException`. The actual HMAC comparison (`hash_equals`) then runs unconditionally when a key is present. No other files changed.

## Decisions Made

- **No boot-time AppServiceProvider assertion added:** The audit item flagged this as optional ("belt-and-suspenders"). The RuntimeException thrown at request time is sufficient and keeps blast radius minimal — adding a boot assertion would require touching AppServiceProvider and has no coverage gap given the middleware fires on every request.
- **Comment verbosity matched VerifyHydrogenApiKey:** Kept the multi-line explanatory comment to preserve the same documentation standard and explicitly name the production-deploy scenario being guarded against.

## Notes

The fix is structurally identical to the Hydrogen middleware — a straightforward copy of the existing pattern. No surprising findings. The `$next($request)` early-return inside the empty-key block now skips the shop-domain resolution too, which is correct behavior for local/testing bypass (no Shopify shop header is needed in tests).

## Questions Asked
(none)
