---
item_id: '#GS-2'
title: Add `CACHE_STORE=failover` config so a Redis outage degrades to "no cache"
  instead of "site down"
source: audit-2026-05-07-caching-foundation.md
tier: P2
effort_estimate: S
completed_at: '2026-05-08T03:50:31+00:00'
mode: manual
commit_sha: 5e70dda6a6a6076146ee1377d32c2b88353ca68d
files_touched:
- .env.example
- config/cache.php
test_result: pass
questions_asked: 0
---

# #GS-2 — Add `CACHE_STORE=failover` config so a Redis outage degrades to "no cache" instead of "site down"

## Plain English

If Redis goes down, the site previously went with it — every `Cache::get` threw a `Predis\ClientException` and bubbled up as a 500. Now the cache layer is configured to silently fall through to a per-worker in-memory store (`array`) instead. Slower (no cross-worker hit rate), but the app stays up. Same idea as a generator kicking in when the power fails.

## Technical Summary

Two-line change:

- `config/cache.php` — the existing `failover` store chained `database → array`. Updated to chain `redis → array` so a Redis outage falls through to the in-process array driver instead of needing a database that may also be unreachable. Inline shorthand `'stores' => ['redis', 'array']`.
- `.env.example` — `CACHE_STORE=redis` → `CACHE_STORE=failover` so any new env inherits the safer default.

`CacheLockService` keeps working under fallback because the lock connection (`cache_locks`, separate Redis DB) is independent of the cache store, AND because the `array` driver provides in-process locking — adequate for the outage window since locks are only relied on for single-flight regeneration of the same key within one worker.

## Decisions Made

- **Reuse existing `failover` block instead of adding a new store**: the block was already there from a Laravel-default scaffold but pointed at `database`, which doesn't help us (no DB cache table, and DB outages tend to correlate with Redis cluster issues anyway). Editing in place keeps `config/cache.php` minimal.
- **Inline `['redis', 'array']` shorthand**: clearer at a glance than a multiline array for a 2-element list.
- **Set in `.env.example` not `.env`**: per CLAUDE.md "never modify .env directly". Production env is set out-of-band.

## Notes

- **Why this completion record exists despite an apparent orchestrator block**: the audit orchestrator ran GS-2 at 13:39 UTC on 2026-05-08 and the patch applied cleanly, but `composer test` then failed on `Tests\Feature\Security\MiddlewarePriorityTest::it returns 401 (not 500) on /api/me without auth`. That failure was a **false positive** — `/api/me` was returning 500 because of an unrelated parse error introduced by commit `1f63901` (botched delete in `ProfessionalController.php`), not because of the failover config change. The orchestrator marked the item `blocked: tests failed`, the patch capture itself failed (0-byte `.audit-work/blocked/GS-2.patch`), and the work was reapplied manually as `5e70dda`. The parse error itself was fixed in `b55216b`. The runtime `state.json` still shows GS-2 as blocked at the time of writing — orchestrator will reconcile from this completion record on the next run.
- **Verification**: targeted `MiddlewarePriorityTest` slice now passes (2/2 in 0.16s) on `development-v2` HEAD with both the failover store change and the parse-error fix landed. The wider Professional/Auth/Middleware test suite (328 tests) also passes.
- **Follow-up not in scope here**: pair with a Nightwatch alert on the failover-rate metric so a degraded-mode outage is still visible — this is mentioned in the audit item's "Technical" section but the alert wiring lives in the Nightwatch dashboard, not the repo, so it's tracked separately.

## Questions Asked
(none)
