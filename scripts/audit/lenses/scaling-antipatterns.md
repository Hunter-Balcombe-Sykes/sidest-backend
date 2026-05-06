# Scaling antipatterns: write amplification, rebuild-on-write, weak caching

Find write-amplification, rebuild-on-write, and weak-caching patterns elsewhere in the codebase, modelled on the antipatterns we just eliminated from commerce analytics + commission accounting (see `docs/analytics-rebuild-plan.md` v3.1 and `docs/adr/0001-analytics-rebuild.md`, deployed 2026-05-06).

## Background — what was just fixed

The old commerce design wrote one `commission_ledger_entries` row per Shopify line item and `DELETE`-then-`INSERT`-rebuilt six aggregate tables on every webhook; site analytics dispatched `RebuildSiteHourlyAggregatesJob` on every pageview/click. The replacement is `commerce.orders` + `commerce.order_events` (append-only audit log) + trigger-maintained `brand_affiliate_rollup` (signed-delta upsert), with live queries fronted by `CacheLockService::rememberLocked` (60s TTL + ±20% jitter + SWR + push invalidation on every write).

The same antipattern shape almost certainly exists elsewhere. Find it.

## Use the lens prefix `CACHE` for findings

Number them `CACHE-1`, `CACHE-2`, … sequentially across the whole audit, regardless of category.

## Findings categories

### (1) Rebuild-on-write
Jobs matching `Rebuild*AggregatesJob`, services with `rebuild*Day` / `rebuild*Hour` / `rebuild*Period` methods that `DELETE`-then-`INSERT` or recompute-and-overwrite an aggregate per event. Known suspects: `app/Jobs/Analytics/RebuildBooking{Daily,Hourly}AggregatesJob.php` and `BookingAnalyticsAggregateService`. Flag any dispatch site that fires a recompute job in response to a single user or webhook event. The canonical replacement is a trigger-maintained signed-delta rollup or — if cardinality is low enough — a live query + cache.

### (2) Write amplification
Handlers that produce N rows per event where N is unbounded by event payload size (one row per line item, per cart item, per recipient, per receipt). Synchronous per-row write loops inside webhook handlers. New `*_ledger` / `*_entries` / `*_items` / `*_receipts` projections that become the source of truth for cohort-level reads when a single row + JSONB + AFTER-UPDATE trigger would suffice (the `order_items` pattern in the rebuild).

### (3) Weak cache on hot reads
- `Cache::remember` or `cache()->remember` in dashboard controllers/services without single-flight (no `CacheLockService::rememberLocked` lock) — stampede risk on cold cache after a deploy or eviction.
- Caches with no TTL jitter on hot keys (synchronised expiration → thundering herd).
- Caches with TTL only and no push-invalidation on the write path (dashboard goes stale rather than fresh).
- Cache facades that don't pin to Redis (file driver fallback defeats the cache).
- Missing version-token pattern (`analyticsSummaryVersion`) where an upstream config flip should bust a cache.
- `INF` / `null` / `0` TTLs on hot keys.
- `Cache::forget` on a single key where a prefix-flush is needed (or vice versa).

### (4) Aggregate tables that should be live queries
Per-day or per-hour rollup tables read by exactly one dashboard query whose source is well-indexed enough that `SUM`/`COUNT` over a date range is sub-100ms at expected scale (target: 30 brands × ~50 affiliates × ~100 orders/affiliate/year, equivalent ratios for site analytics / bookings / cart events / notifications). Aggregate columns that always equal `SUM(child.x) WHERE child.parent_id = id` and are kept in sync via observers — a DB-level rollup is safer.

### (5) Hot-path heavy work and fan-out
- Shopify / Stripe webhook controllers and jobs doing synchronous full re-aggregation, multi-table `DELETE`+`INSERT`, or large JSONB normalisation on the request thread.
- Notification fan-out jobs (`FanOutBrandStatusNotificationJob`, `SendStaffBroadcastEmailsJob` and similar) that dispatch one child job per `NotificationReceipt` created eagerly at fan-out time rather than lazily on first read/click.
- Observer hooks that dispatch multiple jobs per save (chain or batch them).
- Eager-loaded Eloquent collections that hydrate full models where a `selectRaw` aggregate would do.

### (6) Append-only / mutable confusion
- Tables that are functionally an audit log but get `UPDATE`d (loses auditability — should be append-only event log + separate mutable projection).
- Tables that are functionally a projection but are append-only (forces O(N) scans for current state — should be mutable with an event log alongside).
- For notifications specifically: distinguish the immutable "notification was sent" record from the mutable "user-X read state" — they belong in separate tables.

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- Name the canonical replacement: live query + `rememberLocked` + jitter + SWR + push-invalidate, OR trigger-maintained signed-delta rollup, OR append-only event log + mutable projection, OR chunked/batched fan-out.
- Quantify expected impact at pre-beta vs. the scaling target above (30 brands × ~50 affiliates × ~100 orders/affiliate/year and equivalent ratios for site analytics / bookings / cart events / notifications).

## Out of scope — do NOT re-flag (already fixed by the rebuild deployed 2026-05-06)

- `app/Models/Commerce/*` and the new `commerce.orders` / `order_events` / `order_items` / `brand_affiliate_rollup` / `commission_movements` schema
- `app/Services/Stripe/CommissionPayoutService` and `CommissionVoidService`
- `app/Http/Controllers/Api/Internal/Embedded*` analytics controllers (already on the new live-query path)
- The deleted `commission_ledger_entries` model and its observer
- Any `RebuildSite*AggregatesJob` references that were already removed in Phase 4

## Suggested high-value, non-commerce targets

- `app/Jobs/Analytics/Rebuild*AggregatesJob.php` (booking)
- `app/Services/Analytics/BookingAnalyticsAggregateService.php` and any sibling non-commerce services
- `app/Observers/` (per-save dispatch points outside commerce)
- `app/Services/Cache/CacheKeyGenerator.php` and every `Cache::remember` / `cache()->remember` call site outside commerce
- `app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php` (booking sections)
- `app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php`
- Site analytics ingest paths (`site_visits`, `link_clicks`, `cart_events` handlers/jobs)
- Notifications: `app/Jobs/Notifications/`, `app/Services/Notifications/` (`CommerceNotificationService`, `NotificationPublisher`), `app/Models/Core/Notifications/`, `app/Http/Controllers/Api/Professional/Notifications/`, `app/Http/Controllers/Api/Staff/StaffSite/StaffNotification*Controller.php`

## Exhaustiveness directive

Do NOT stop after the first finding in a category. Walk every file in scope and emit a finding for every distinct instance you can quote evidence for. If three controllers each have an unlocked `Cache::remember`, that is three findings (`CACHE-1`, `CACHE-2`, `CACHE-3`), not one consolidated finding. If two rebuild jobs share the antipattern, emit two findings. If a fan-out job has both unbounded `foreach` and missing batching, that is two findings. The adjudicator will dedupe and re-tier; **under-reporting is the failure mode to avoid**. Aim for breadth over consolidation — keep going until every file in `--scope` has been read and every distinct quotable instance is recorded.
