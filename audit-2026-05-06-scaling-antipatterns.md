`★ Insight ─────────────────────────────────────`
Three calibration patterns found in this draft: (1) DeepSeek correctly identified the booking rebuild antipattern but missed that the aggregate tables may have no read path — folded into CACHE-1/2 notes rather than a separate finding. (2) CACHE-6's `DateTimeInterface`-bypasses-jitter issue is a genuine P2, not P3 — at 80+ simultaneous users the lack of expiry spread produces cross-key thundering herd even though per-key single-flight works. (3) CACHE-13 was dropped (confidence 0.65 < threshold, not security/data), with its "verify before fixing" directive absorbed into CACHE-1/2.
`─────────────────────────────────────────────────`

# Scaling Antipatterns: Write Amplification, Rebuild-on-Write, Weak Caching Audit — 2026-05-06

**Branch:** development-v2
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php
- app/Jobs/Analytics/RebuildBookingHourlyAggregatesJob.php
- app/Services/Analytics/BookingAnalyticsAggregateService.php
- app/Services/Analytics/Concerns/ResolvesTimezone.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/BrandAffiliateInviteObserver.php
- app/Observers/Core/BrandProfileObserver.php
- app/Observers/Core/CommissionMovementObserver.php
- app/Observers/Core/CommissionPayoutObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/ProfessionalIntegrationObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Professional/ProfessionalObserver.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/SiteCacheService.php
- app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/InviteExpirySweepJob.php
- app/Jobs/Notifications/SendBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php
- app/Services/Notifications/CommerceNotificationService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Models/Core/Notifications/EmailSubscription.php
- app/Models/Core/Notifications/Notification.php
- app/Models/Core/Notifications/NotificationReceipt.php
- app/Http/Controllers/Api/Professional/Notifications/NotificationController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 8 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#CACHE-1** · P1 — Rebuild-on-write: `rebuildProfessionalDay` DELETE-then-INSERT per booking event (Category 1)
    - **Where:** app/Services/Analytics/BookingAnalyticsAggregateService.php:87–130 · app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php
    - **Affects:** Every booking completion that dispatches `RebuildBookingDailyAggregatesJob` — full re-scan of all booking events for that professional and day. Booking dashboard consumers reading stale aggregates between rebuilds.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - **Before implementing anything**: search the codebase for all `SELECT`s against `analytics.booking_metrics_daily`. If no read path exists, the correct fix is to delete both rebuild jobs and the aggregate tables entirely (following the Phase 4 precedent from commit `b0d6043`), not to optimize them.
        - If a read path does exist: replace the DELETE-then-INSERT with a trigger-maintained signed-delta upsert on `analytics.booking_metrics_daily` — `ON CONFLICT (professional_id, day, currency_code) DO UPDATE SET bookings_count = rollup.bookings_count + EXCLUDED.bookings_count, ...` — the `brand_affiliate_rollup` pattern deployed in Phase 3.
        - If the read query can tolerate a live `SUM`/`COUNT` over `analytics.booking_events` with an index on `(professional_id, occurred_at)` (sub-millisecond at 100 bookings/pro/year), drop the aggregate table and serve the dashboard via `CacheLockService::rememberLocked` with a 60s TTL + jitter + push-invalidation.
        - Remove the `pg_advisory_xact_lock` once the trigger-upsert or live-query path is in place — no application-level serialisation is needed.
    - **Technical:** `rebuildProfessionalDay` wraps a `DELETE WHERE professional_id = ? AND day = ?` followed by a `SELECT … GROUP BY currency_code` over `analytics.booking_events` and an `INSERT`. This is the verbatim antipattern eliminated from commerce in Phase 3: full tear-down-and-rebuild per event. Even with `pg_advisory_xact_lock(hashtext('analytics-rebuild:{id}'))` serialising concurrent runs, every dispatch pays O(N) over that day's raw events where N grows with bookings. At the pre-beta target of 30 brands × 50 affiliates × 100 bookings/affiliate/year, a single-day bucket holds <1 booking on average — negligible now, but the pattern is structurally unbounded. The canonical replacement (trigger-maintained signed-delta) is O(1) per event. Note: `RebuildBookingHourlyAggregatesJob` and `RebuildBookingDailyAggregatesJob` share the same advisory lock token (`analytics-rebuild:{professionalId}`), meaning a daily rebuild blocks an hourly rebuild for the same professional unnecessarily.
    - **Plain English:** Every time a booking is recorded, the system erases the entire day's booking tally for that person and recounts everything from scratch — even if there was only one booking all day. The commerce system went through exactly this same problem and was fixed so that each new booking just adds one to a running total rather than erasing and recounting the whole board. Check first whether anything even reads this tally board — if nothing does, just remove it.
    - **Evidence:**
        ```php
        DB::table('analytics.booking_metrics_daily')
            ->where('professional_id', $professionalId)
            ->where('day', $day)
            ->delete();

        $rows = DB::table('analytics.booking_events as e')
            ->where('e.professional_id', $professionalId)
            ->whereBetween('e.occurred_at', [$utcFrom, $utcTo])
            ->select([
                'e.currency_code',
                DB::raw('COUNT(*) as bookings_count'),
                DB::raw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents'),
                // ...
            ])
            ->groupBy('e.currency_code')
            ->get();
        ```

- [ ] **#CACHE-2** · P1 — Rebuild-on-write: `rebuildProfessionalHour` DELETE-then-INSERT per booking event (Category 1)
    - **Where:** app/Services/Analytics/BookingAnalyticsAggregateService.php:28–68 · app/Jobs/Analytics/RebuildBookingHourlyAggregatesJob.php
    - **Affects:** Same as CACHE-1 — every dispatch site per booking event, and hourly-granularity dashboard consumers.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Apply the same "verify read path first" gate as CACHE-1. If `analytics.booking_metrics_hourly` has no consumers, delete the job and table.
        - If hourly granularity is needed: evaluate whether `DATE_TRUNC('hour', occurred_at)` over a well-indexed `analytics.booking_events` is fast enough (should be sub-10ms at expected scale). If yes, serve via live query + `rememberLocked`; if no, add a Postgres trigger for signed-delta upsert.
        - Fix the shared advisory lock token: hourly and daily rebuild jobs both acquire `analytics-rebuild:{professionalId}`, serialising them unnecessarily. Separate to `analytics-rebuild-hourly:{id}` and `analytics-rebuild-daily:{id}`.
    - **Technical:** Identical DELETE-then-SELECT-then-INSERT pattern to CACHE-1, scoped to hourly buckets. The shared advisory lock token (`analytics-rebuild:{professionalId}`) is an additional correctness issue: if a booking event simultaneously dispatches both hourly and daily jobs for the same professional, one blocks the other even though they operate on non-overlapping aggregate rows. With a trigger-maintained signed-delta, no lock is needed — Postgres handles row-level concurrency atomically via `ON CONFLICT … DO UPDATE`.
    - **Plain English:** Same erase-and-recount problem as CACHE-1, but on an hourly whiteboard. There's an additional quirk: the hourly and daily whiteboards use the same "do not disturb" sign, so updating one locks out the other even though they're tracking completely different things.
    - **Evidence:**
        ```php
        DB::table('analytics.booking_metrics_hourly')
            ->where('professional_id', $professionalId)
            ->where('hour_start', $hour)
            ->delete();

        $rows = DB::table('analytics.booking_events as e')
            ->where('e.professional_id', $professionalId)
            ->where('e.occurred_at', '>=', $hour)
            ->where('e.occurred_at', '<', $hourEnd)
            ->select([
                'e.currency_code',
                DB::raw('COUNT(*) as bookings_count'),
                // ...
            ])
            ->groupBy('e.currency_code')
            ->get();
        ```

---

## P2 — Should fix

- [ ] **#CACHE-4** · P2 — Weak cache: `StaffStatsController::show` has zero caching on the ops dashboard (Category 3)
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php:11–44
    - **Affects:** Staff ops dashboard — every page load executes three aggregate queries across `core.professionals`, `billing.subscriptions`, and `commerce.commission_movements`. Low traffic now; any monitoring poll or team growth multiplies the load with no headroom.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` into `StaffStatsController` and wrap the three queries in a single `rememberLocked` call with a 60-second integer TTL (jitter applies automatically).
        - Use a fixed key such as `staff:platform-stats` — no per-professional scoping needed since these are platform-wide totals.
        - No push-invalidation required at this traffic level; 60s staleness is fully acceptable for an ops dashboard.
    - **Technical:** `show` performs three separate aggregate queries on every request with no caching: `COUNT(*) GROUP BY professional_type` over `core.professionals`, `COUNT(*)` over `billing.subscriptions`, and `SUM(amount_cents)` over `commerce.commission_movements`. None of these values change faster than seconds. At pre-beta these run perhaps once an hour; a single monitoring integration polling every 30s would multiply them 120× per hour. `rememberLocked` with a 60s TTL collapses N requests into 1 DB hit per minute and adds SWR protection at zero marginal cost.
    - **Plain English:** The staff dashboard runs three "count everything" database queries every time someone opens the page. Right now that might be once an hour. But any automated monitoring tool refreshing every 30 seconds turns those into 120 queries an hour for three tables. A 60-second sticky-note cache means the database is only asked once per minute, no matter how many people or bots check the page.
    - **Evidence:**
        ```php
        $typeCounts = DB::table('core.professionals')
            ->whereNull('deleted_at')
            ->selectRaw('professional_type, count(*) as total')
            ->groupBy('professional_type')
            ->pluck('total', 'professional_type');

        $activeSubscriptions = DB::table('billing.subscriptions')
            ->whereNull('ended_at')
            ->count();

        $pendingCommissionCents = DB::table('commerce.commission_movements')
            ->where('status', 'pending')
            ->sum('amount_cents');
        ```

- [ ] **#CACHE-9** · P2 — Write amplification: `InviteExpirySweepJob` issues one UPDATE per expired invite instead of a batch (Category 2)
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:35–62
    - **Affects:** Nightly invite expiry sweep — each expired invite generates a separate Postgres round-trip for the status update. Under a bulk CSV import that expires simultaneously, 500 individual UPDATEs per chunk instead of one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect all invite IDs within each chunk, then issue a single `UPDATE … SET status = 'expired' WHERE id IN (…) AND status = 'pending'` before the notification loop.
        - The `WHERE status = 'pending'` guard remains, preserving idempotency.
        - Keep the per-invite notification publish inside the `foreach` — each notification has a unique `dedupeKey`, so per-row is correct there.
    - **Technical:** The job correctly chunks by 500 rows (memory-safe), but inside each chunk each row gets its own `UPDATE` with a separate Postgres round-trip and WAL write. `WHERE id IN (…)` over a UUID list of ≤500 rows is a single round-trip and a single WAL record — strictly better in all dimensions. At the nightly sweep cadence and current invite volume this is negligible, but the pattern is the wrong shape for future growth (bulk partner onboarding campaigns can create thousands of simultaneous invites).
    - **Plain English:** When invite expiry runs nightly it marks expired invites one at a time — like crossing names off a list one pen stroke per person instead of drawing one line through all of them at once. The notifications still need to go out individually (each message has a unique ID), but the "mark as expired" database command can be done in one shot for the whole batch.
    - **Evidence:**
        ```php
        foreach ($chunk as $invite) {
            try {
                DB::table('brand.brand_affiliate_invites')
                    ->where('id', $invite->id)
                    ->where('status', 'pending') // guard against concurrent updates
                    ->update(['status' => 'expired', 'updated_at' => $now]);

                // ... then publish notification per invite
            } catch (\Throwable $e) { ... }
        }
        ```

- [ ] **#CACHE-6** · P2 — Weak cache: `ProfessionalAnalyticsController::summary` passes `DateTimeInterface` TTL, bypassing jitter — cross-key thundering herd (Category 3)
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php (~line 55–57)
    - **Affects:** Professional analytics dashboard — "today" queries produce keys that all expire at exactly the same wall-clock instant for every professional who loaded at the same time. At 80 concurrent users (30 brands × 50 affiliates), 80 independent parallel rebuilds fire simultaneously every 5 minutes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the `DateTimeInterface` TTLs to integer seconds: `300` for 5-minute ("today") queries and `86400` for 24-hour (historical) queries.
        - `CacheLockService::writeWithJitter` already applies ±20% jitter to integer TTLs automatically — no other change needed.
    - **Technical:** `$cacheTTL = $to->isToday() ? now()->addMinutes(5) : now()->addHours(24)` produces a `Carbon` object (implements `DateTimeInterface`). Inside `CacheLockService::writeWithJitter`, the `if ($ttl instanceof DateTimeInterface)` branch writes the key with an exact deadline and returns — jitter is never applied. Per-key single-flight (`rememberLocked`) prevents stampede on a single cache key, but with 80+ different professional IDs each producing their own key, all 80 keys expire at the same instant, triggering 80 parallel DB rebuilds simultaneously. Switching to `300` / `86400` integer TTLs spreads expiry across a ±60s / ±4800s window automatically via `writeWithJitter`'s existing `0.8 + mt_rand(0, 4000) / 10000.0` jitter formula.
    - **Plain English:** Every professional's dashboard cache expires at exactly the same time as every other professional who loaded at the same moment. If 80 users all open the dashboard at 9:00 AM, 80 separate database queries all fire at exactly 9:05 AM simultaneously. Switching from a specific clock-time expiry to a "5 minutes from now, plus or minus a random 1-minute wiggle" spreads those 80 rebuilds across a 2-minute window so the database doesn't get a spike every 5 minutes.
    - **Evidence:**
        ```php
        $cacheTTL = $to->isToday() ? now()->addMinutes(5) : now()->addHours(24);

        $data = $this->cacheLock->rememberLocked($cacheKey, $cacheTTL, function () use (...) {
        ```

- [ ] **#CACHE-5** · P2 — Weak cache: `SiteCacheService::getPublicSitePayload` hardcodes flat 15-minute TTL with no jitter, creating synchronised expiry waves (Category 3)
    - **Where:** app/Services/Cache/SiteCacheService.php (three `Cache::put` calls inside `getPublicSitePayload`)
    - **Affects:** Public site payload — 95% of frontend traffic. If a deploy or `Cache::flush()` clears all site payloads simultaneously, the next expiry cycle 15 minutes later will see every site key expire at exactly the same instant.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Port `getPublicSitePayload` to use `CacheLockService::rememberLocked` in place of the hand-rolled `Cache::lock` + `Cache::put` combination. This gets jitter, SWR, and lock semantics in one call.
        - If retaining the manual lock approach (to keep the existing negative-cache sentinel logic inline), apply ±20% jitter by replacing `now()->addMinutes(15)` with an integer seconds value (`900`) passed through `CacheLockService::writeWithJitter` directly.
    - **Technical:** The method implements single-flight correctly via `Cache::lock('site:fill:'.$subdomain, 10)` — cold-cache stampede per key is handled. However, both the cache-healing write (`Cache::put($key, $cached, now()->addMinutes(15))`) and the fresh-build write (`Cache::put($key, $data, now()->addMinutes(15))`) use exact flat TTLs. After a fleet-wide flush where all sites rebuild during a short window post-deploy, all rebuilt keys expire at the same instant 15 minutes later, creating a synchronised wave of fill-lock acquisitions. At 30 brands × 50 affiliates = 80+ active site subdomains, that's 80+ near-simultaneous rebuilds. `CacheLockService::writeWithJitter` with integer TTL `900` spreads expiry across ±180s (12–18 min window) for free.
    - **Plain English:** Every site's cached homepage data expires exactly 15 minutes after it was built. If a restart rebuilds all 80 sites in the same minute, they all expire at exactly the same time 15 minutes later — triggering another wave of 80 simultaneous rebuilds. A random 3-minute wiggle on each expiry time spreads those rebuilds across a window so the system never gets a spike.
    - **Evidence:**
        ```php
        Cache::put($key, $cached, now()->addMinutes(15));  // cache-healing write

        Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(30));  // negative cache

        Cache::put($key, $data, now()->addMinutes(15));  // fresh build
        ```

- [ ] **#CACHE-3** · P2 — Weak cache: `SiteCacheService::getSiteLinkBlocks` uses bare `Cache::remember` without single-flight lock — stampede risk on public site hot path (Category 3)
    - **Where:** app/Services/Cache/SiteCacheService.php (`getSiteLinkBlocks` method)
    - **Affects:** Frontend public site rendering — block lists read on every page load for any site using link blocks. On cold cache (post-deploy, post-flush), every concurrent request independently rebuilds the block list.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` into `SiteCacheService` (it currently has no constructor dependencies).
        - Replace `Cache::remember` with `$this->cacheLock->rememberLocked(...)` using an integer TTL of `900` (15 min, for jitter).
        - `BlockObserver` already calls `invalidateSite`, which deletes `CacheKeyGenerator::siteBlocks($site->id, 'links')` — push-invalidation is already in place. No additional work needed on the write path.
    - **Technical:** `getSiteLinkBlocks` uses plain `Cache::remember` with a `DateTimeInterface` TTL and no lock. On a cold cache, every concurrent request for the same site independently executes the `Block::query()->where('site_id',...)->active()->orderBy()->get()` Eloquent query — a classic stampede. `getPublicSitePayload` in the same class uses `Cache::lock('site:fill:'.$subdomain, 10)` for single-flight. `AnalyticsCacheService` and `ProfessionalCacheService` use `rememberLocked`. This method is the only hot-path cache call in the file not using the canonical pattern. The fix is a two-line change plus a constructor dependency injection.
    - **Plain English:** When the site cache is empty (after a restart or deploy), every visitor triggers their own database query to rebuild the same block list. If 100 people visit at the same moment, that's 100 identical queries. The "wait your turn" lock tool already exists in the project and is used everywhere else — it just wasn't wired in here.
    - **Evidence:**
        ```php
        public function getSiteLinkBlocks(string $siteId): array
        {
            return Cache::remember(
                CacheKeyGenerator::siteBlocks($siteId, 'links'),
                now()->addMinutes(15),
                fn () => Block::query()
                    ->where('site_id', $siteId)
                    ->where('block_group', 'links')
                    ->active()
                    ->orderBy('sort_order')
                    ->get()
                    ->toArray()
            );
        }
        ```

- [ ] **#CACHE-7** · P2 — Fan-out: `FanOutBrandStatusNotificationJob` dispatches one child job per affiliate in an unbounded `foreach` (Category 5)
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:52–61
    - **Affects:** Every brand status transition (`live → building`, `building → live`, `systems_down`) — dispatches one `SendBrandStatusNotificationJob` per connected affiliate. At 30 brands × 50 affiliates = 1,500 Redis writes per simultaneous brand status change event.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `foreach` dispatch loop with `Bus::batch(...)` — collect all `SendBrandStatusNotificationJob` instances in the chunk and dispatch as a single batch. This reduces Redis round-trips from O(N) to O(1) per chunk.
        - Alternatively, add a short randomised delay to each child dispatch (`->delay(now()->addSeconds(rand(1, 30)))`) to spread queue pressure and avoid thundering-herd on `SendBrandStatusNotificationJob` workers.
        - At current scale (≤50 affiliates/brand), this is low-urgency; the pattern is worth fixing before affiliate counts become unbounded.
    - **Technical:** The job chunks by 500 rows (good for memory) but then `foreach`-dispatches individual jobs with no batching. Each `SendBrandStatusNotificationJob::dispatch(...)` is a synchronous Redis `RPUSH` — at 50 affiliates, 50 separate pushes in a tight loop. If a brand grows to 5,000 affiliates, that's 5,000 Redis writes fired within a single job execution. `Bus::batch()` writes the entire chunk to Redis in a single pipeline call. The `InviteExpirySweepJob` in the same directory demonstrates inline processing as an alternative — but for this fan-out case, child jobs for per-recipient isolation on retry are appropriate; batching is the right fix.
    - **Plain English:** When a brand changes its program status, the system creates a separate "send this notification" task for each connected affiliate — like writing 50 individual Post-it notes instead of one list. At 50 affiliates this is fine. At 500 it's a blizzard of tasks hitting the queue simultaneously. Batching them into groups means the queue system gets one delivery of 500 notes rather than 500 separate deliveries.
    - **Evidence:**
        ```php
        DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->chunkById(500, function ($rows) use ($brandName, $yearWeek) {
                foreach ($rows as $row) {
                    SendBrandStatusNotificationJob::dispatch(
                        affiliateProfessionalId: $row->affiliate_professional_id,
                        brandProfessionalId: $this->brandProfessionalId,
                        brandName: $brandName,
                        brandStatus: $this->brandStatus,
                        yearWeek: $yearWeek,
                    );
                }
            });
        ```

- [ ] **#CACHE-10** · P2 — Hot-path heavy work: `notifyBookingMilestonesForProfessional` runs a full `COUNT(*)+SUM` over `booking_events` on every booking completion (Category 5)
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:120–157
    - **Affects:** Every booking completion — triggers a full sequential scan of `analytics.booking_events` for that professional to check 6 count thresholds and 6 revenue thresholds. Scan grows O(N) with total bookings per professional.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `rememberLocked` cache in front of the `COUNT(*)+SUM` query with a short TTL (60s) and the professional's booking event cache key. This limits the scan to at most once per minute per professional.
        - For a more durable fix: maintain a counter row in a `booking_milestone_counters` table (`professional_id`, `bookings_count`, `total_spent_cents`) updated by a Postgres trigger on `analytics.booking_events INSERT`, making the milestone check O(1) instead of O(N).
        - The `dedupeKey` on the publisher call (`'booking:count:'.$bookingMilestone`) provides idempotency at the notification layer — the milestone notification will only ever be created once per threshold level per professional, so the underlying scan is pure overhead after the first trigger.
    - **Technical:** On every booking completion, `notifyBookingMilestonesForProfessional` runs `SELECT COUNT(*), COALESCE(SUM(amount_paid_cents), 0) FROM analytics.booking_events WHERE professional_id = ?`. At 100 bookings/year per professional, this is trivial. At 1,000 lifetime bookings, it's a growing sequential scan for every new booking. The milestone check only needs to know whether the count crossed 1, 5, 10, 25, 50, or 100 — six threshold values. After the 100th booking, the `latestReachedThreshold` returns 100 every time and `insertOrIgnore` on `(professional_id, dedupe_key)` silently no-ops. The scan is 100% wasted work after the highest milestone is reached. A 60s cache cap is the minimum viable fix; a trigger-maintained counter is the canonical replacement.
    - **Plain English:** Every time a booking is completed, the system counts all of that professional's bookings from the beginning of time — just to check if they've hit a milestone like "100th booking." Once they've passed the 100th booking, every subsequent booking triggers this full recount even though the answer never changes. A running tally — add 1 each time — would give the same answer instantly without recounting everything.
    - **Evidence:**
        ```php
        $totals = DB::table('analytics.booking_events')
            ->where('professional_id', $professionalId)
            ->selectRaw('COUNT(*) as bookings_count')
            ->selectRaw('COALESCE(SUM(amount_paid_cents), 0) as total_spent_cents')
            ->first();

        $bookingsCount = max(0, (int) ($totals->bookings_count ?? 0));
        $totalSpentCents = max(0, (int) ($totals->total_spent_cents ?? 0));

        $bookingMilestone = $this->latestReachedThreshold($bookingsCount, self::BOOKING_COUNT_MILESTONES);
        ```

- [ ] **#CACHE-8** · P2 — Fan-out: `SendStaffBroadcastEmailsJob` dispatches one child job per email subscriber in an unbounded `foreach` (Category 5)
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:44–52
    - **Affects:** Every staff platform-wide broadcast email — fans out `SendStaffBroadcastEmailToSubscriberJob` per subscriber. At 10,000 subscribers, 10,000 Redis writes in a tight loop from a single job execution. Unbounded as the subscriber list grows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Use `Bus::batch()` to dispatch child jobs in groups — collect all `SendStaffBroadcastEmailToSubscriberJob` instances within the chunk callback and pass the array to `Bus::batch(...)->dispatch()`. Redis round-trips drop from O(N) to O(chunks).
        - Alternatively: within the `chunkById` callback, send emails inline (direct `Mail::to(...)->send(...)`) with per-recipient `try/catch`, removing `SendStaffBroadcastEmailToSubscriberJob` as a separately queued unit. Keep child job dispatch only if per-subscriber independent retry semantics are required.
        - Add a `delay` spread across the batch to avoid overwhelming the mail driver on large sends.
    - **Technical:** Unlike `FanOutBrandStatusNotificationJob` (bounded by affiliates-per-brand, currently ~50), this fan-out is unbounded — every `EmailSubscription` with `status = 'subscribed'` and matching `list_key` gets a dedicated job. At 10,000 subscribers, `foreach`-dispatching produces 10,000 synchronous Redis `RPUSH` calls inside the job's `handle()` method. Horizon must then deserialize and route each of the 10,000 payloads. `Bus::batch()` pipelines the Redis writes, reducing them to O(chunks) = O(10,000/500) = 20 round-trips. The child job `SendStaffBroadcastEmailToSubscriberJob` already has the right unsubscribe-after-queue-time check (`if ($sub->status !== 'subscribed') { return; }`), so per-subscriber retry isolation is preserved with batching.
    - **Plain English:** Sending a platform announcement to 10,000 subscribers creates 10,000 separate "send this one email" tasks all at once in a tight loop — like hiring 10,000 individual couriers, one per letter, rather than giving one courier a bag. Each courier costs overhead to hire (Redis writes), and 10,000 couriers arriving at the post office simultaneously creates a traffic jam. Batching delivers 500 letters per courier in 20 trips instead of 10,000 trips of 1.
    - **Evidence:**
        ```php
        EmailSubscription::query()
            ->whereNull('professional_id')
            ->where('list_key', $this->listKey)
            ->where('status', 'subscribed')
            ->orderBy('id')
            ->chunkById(500, function ($subs) use ($notification) {
                foreach ($subs as $sub) {
                    SendStaffBroadcastEmailToSubscriberJob::dispatch(
                        $notification->id,
                        $sub->id
                    )->onQueue('mail');
                }
            });
        ```

---

## P3 — Nice to have

- [ ] **#CACHE-12** · P3 — Observer fan-out: `ServiceObserver` dispatches up to 2 external sync jobs per service save (Category 5)
    - **Where:** app/Observers/Core/ServiceObserver.php:31–47 (`runHooks` calling `dispatchSquareSync` and `dispatchFreshaSync`)
    - **Affects:** Every service create/update/delete/restore — may dispatch `PushServiceToSquareJob` and `PushServiceToFreshaJob`. Under bulk CSV import of 50 services, up to 100 sync jobs hit the `integrations` queue in a tight loop.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For the bulk import scenario: collect service IDs into a static accumulator in the observer and dispatch a single `BatchSyncServicesJob` via `dispatchAfterResponse()` — batch syncing all IDs in one external API pass.
        - Short-term: add a debounce delay (`->delay(now()->addSeconds(rand(5, 30)))`) to spread external API calls and reduce rate-limit exposure.
    - **Technical:** `shouldDispatchSquareSync` and `shouldDispatchFreshaSync` are well-guarded — both check feature flags, integration availability, and `services_auto_sync_enabled`. For individual service edits (the common case), 0–2 jobs is acceptable. The concern is bulk import: 50 services × 2 integrations = 100 jobs hitting external APIs simultaneously. Square and Fresha impose rate limits; simultaneous hits from 100 jobs increase the probability of 429 responses and retry backpressure. The `->afterCommit = true` flag on the observer ensures jobs only dispatch on successful commits, which is correct.
    - **Plain English:** When someone edits a service, the system can send up to two sync updates — one to Square, one to Fresha — to keep those platforms current. For one edit, that's fine. But if someone imports 50 services at once, 100 sync tasks fire simultaneously and may hit Square and Fresha's rate limits, causing them all to fail and retry.
    - **Evidence:**
        ```php
        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, $action);
        }
        if ($this->shouldDispatchFreshaSync($pro)) {
            $this->dispatchFreshaSync($service->id, $action);
        }
        ```

- [ ] **#CACHE-11** · P3 — Observer side-effect: `EmailSubscription::booted` runs a synchronous `Customer` lookup + `saveQuietly` on every subscription save (Category 5)
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php (`booted` static `saved` hook)
    - **Affects:** Any save to `notifications.email_subscriptions` — subscribe, unsubscribe, bulk import. Under a bulk CSV import of 1,000 subscribers, 1,000 additional `Customer` lookups + conditional `saveQuietly` calls execute synchronously on the import thread.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `Customer` cache sync to a queued job (`SyncCustomerMarketingOptInJob`) dispatched from the `saved` hook with `afterCommit = true` semantics.
        - The `marketing_opt_in_cached` column on `Customer` is a UX optimisation (contact list display) — a few-second delay between subscribe and cache update is acceptable.
        - For single subscribe/unsubscribe events, the performance impact is negligible; the bulk import path is the motivation.
    - **Technical:** The `saved` observer runs `Customer::query()->where(...)->first()` and `$customer->saveQuietly()` on every `EmailSubscription` save. `saveQuietly()` triggers another round-trip to set `marketing_opt_in_cached`. `BrandProfileObserver` in the same directory demonstrates the correct pattern: dispatch a queued job (`FanOutBrandStatusNotificationJob`) rather than doing synchronous work inline. The Customer cache sync is not urgent — it feeds a display flag — so `afterCommit` queued dispatch is appropriate and eliminates the per-row penalty on bulk imports.
    - **Plain English:** Every time someone subscribes or unsubscribes from marketing emails, the system immediately runs an extra database query to update a "opted in?" display flag on the Contact record. For a single click that's instant. But if someone imports a spreadsheet of 1,000 subscribers, the system does 1,000 extra lookups during the import. Moving that update to a background job means the import finishes faster and the display flag updates a few seconds later — which is fine for something that's only used to show a green tick on a contact card.
    - **Evidence:**
        ```php
        protected static function booted(): void
        {
            static::saved(function (self $subscription) {
                if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                    $customer = \App\Models\Core\Professional\Customer::query()
                        ->where('professional_id', $subscription->professional_id)
                        ->where('email', $subscription->email)
                        ->first();
                    if ($customer) {
                        $customer->marketing_opt_in_cached = $subscription->status === 'subscribed';
                        $customer->saveQuietly();
                    }
                }
            });
        }
        ```
