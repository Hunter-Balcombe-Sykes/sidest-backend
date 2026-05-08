---
item_id: '#CACHE-5'
title: '`invalidateAnalytics` key-enumeration loop uses fixed `$end = today` for every
  iteration, leaving historical date-range keys for visits/clicks undeleted until
  their 5-minute TTL'
source: audit-2026-05-07-caching-foundation.md
tier: P3
effort_estimate: S
completed_at: '2026-05-08T03:21:40+00:00'
mode: overnight
commit_sha: 529588b
files_touched:
- app/Services/Cache/AnalyticsCacheService.php
- tests/Feature/Cache/AnalyticsCacheKeyParityTest.php
test_result: pass
questions_asked: 0
---

# #CACHE-5 — `invalidateAnalytics` key-enumeration loop uses fixed `$end = today` for every iteration, leaving historical date-range keys for visits/clicks undeleted until their 5-minute TTL

## Plain English

When new analytics data arrives, we clear the chart cache for the last 90 days. There was a small scoping bug: instead of building a cache key for "just Tuesday's data," the code was accidentally building "Tuesday through today's data" for every single day in the loop. This meant only same-day keys got cleared; historical ranges like "last week" had to wait for their 5-minute timer. Fixed by making the loop build single-day keys (start = end = that day), and updated the test to match the corrected key shape.

## Technical Summary

**Files changed:**
- `app/Services/Cache/AnalyticsCacheService.php` — In `invalidateAnalytics`, replaced the `$end = Carbon::now()` capture + `$endStr = $end->format('Ymd')` pattern with `$date = Carbon::now()->subDays($i)->format('Ymd')` and used it for both start and end. Also removed the now-unnecessary `$end` variable and simplified the loop body.
- `tests/Feature/Cache/AnalyticsCacheKeyParityTest.php` — Updated the "deletes visit stat cache keys for last 90 days" test to seed a single-day key (`dayStr, dayStr`) instead of the old (`yesterday, today`) shape that matched the bug.

**New contract:** `analyticsVisits` and `analyticsClicks` cache keys are single-day entries keyed as `analytics:visits:{proId}:{Ymd}:{Ymd}` where both date segments are identical. `invalidateAnalytics` clears the rolling 90-day window of these keys on each call.

## Decisions Made

- **Use single-day keys (`$date` for both start and end):** `getVisitStats`/`getClickStats` have no callers yet, so the key format is being established here. Single-day granularity matches the loop's obvious intent (enumerate 90 individual days) and is the natural pattern for time-series chart data. The version-token approach would have been overkill.
- **Update the existing test rather than add a new one:** The test was documenting buggy behavior, not correct behavior. Replacing the seed/assertion values is the right fix — adding a parallel test would have left the bad test in place.

## Notes

`getVisitStats` and `getClickStats` are currently unused (no controller/service callers found). The invalidation loop and the key-generation helpers are being set up ahead of those callers being wired in. The 5-minute TTL on `rememberLocked` remains the real safety net regardless.

## Questions Asked
(none)
