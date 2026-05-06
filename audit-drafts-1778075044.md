- [ ] **#CACHE-1** · P1 — Rebuild-on-write: RebuildBookingDailyAggregatesJob + rebuildProfessionalDay performs DELETE-then-INSERT per professional per day
    - **Where:** app/Jobs/Analytics/RebuildBookingDailyAggregatesJob.php + app/Services/Analytics/BookingAnalyticsAggregateService.php:87-130
    - **Affects:** Any dispatch site that fires this job in response to a single booking event. Booking dashboard consumers reading stale aggregates between rebuilds.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Identify every dispatch site for `RebuildBookingDailyAggregatesJob` and assess whether it fires per booking event (webhook/Square sync/Fresha sync).
        - Replace the DELETE-then-INSERT pattern with an upsert-driven signed-delta rollup using `ON CONFLICT ... DO UPDATE` (the `brand_affiliate_rollup` pattern), or if the read cardinality is low enough, drop the aggregate table entirely and serve dashboard queries as live `SUM`/`COUNT` over `analytics.booking_events` fronted by `CacheLockService::rememberLocked`.
        - Add a Postgres trigger on `analytics.booking_events` as the canonical long-term replacement — increment/decrement the rollup row on INSERT/DELETE.
    - **Technical:** The `rebuildProfessionalDay` method wraps a `DELETE` of all rows for that professional+day followed by a `SELECT … GROUP BY currency_code` over `analytics.booking_events` and an `INSERT`. This is the exact antipattern eliminated in commerce: a complete tear-down-and-rebuild per event. Even with `pg_advisory_xact_lock` serialising concurrent rebuilds, every dispatch point pays a full re-aggregation cost proportional to the number of booking events in that day. At 30 brands × 50 affiliates × ~100 bookings/affiliate/year, a single-day bucket holds ~0.4 bookings on average — a trigger upsert would be O(1) instead of O(N) over the day's events. The canonical replacement is a trigger-maintained signed-delta rollup (`ON CONFLICT (professional_id, day, currency_code) DO UPDATE SET bookings_count = rollup.bookings_count + 1, …`).
    - **Plain English:** Imagine you have a daily sales tally on a whiteboard. Right now, every time a sale happens, someone erases the whole board and re-adds every sale from scratch — even if there was only one sale today. That's what this code does for booking stats. The fix used in the commerce system is to just add one to the running total instead of erasing and recounting. It's the difference between updating a counter and re-reading the whole ledger.
    - **Evidence:**
        ```php
        DB::table('analytics.booking_metrics_daily')
            ->where('professional_id', $professionalId)
            ->where('day', $day)
            ->delete();

        $rows = DB::table('analytics.booking_events as e')
            ->where('e.professional_id', $professionalId)
            ->whereBetween('e.occurred_at', [$utcFrom, $utcTo])
            ->select([...])
            ->groupBy('e.currency_code')
            ->get();
        // ... then INSERT from $rows
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CACHE-2** · P1 — Rebuild-on-write: RebuildBookingHourlyAggregatesJob + rebuildProfessionalHour performs DELETE-then-INSERT per professional per hour
    - **Where:** app/Jobs/Analytics/RebuildBookingHourlyAggregatesJob.php + app/Services/Analytics/BookingAnalyticsAggregateService.php:28-68
    - **Affects:** Same as CACHE-1 — any dispatch site per booking event, and hourly dashboard granularity consumers.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same canonical replacement as CACHE-1: trigger-maintained signed-delta rollup or live query + cache.
        - If the hourly table is only read by the same dashboard that the daily table feeds, consider whether hourly granularity can be served from the raw `booking_events` table via `DATE_TRUNC('hour', occurred_at)` instead of maintaining a separate aggregate.
    - **Technical:** Identical DELETE-then-SELECT-then-INSERT pattern to the daily job, scoped to hourly buckets. The advisory lock on `analytics-rebuild:{professionalId}` serialises hourly and daily rebuilds for the same professional (they share the lock token), which means a daily rebuild can block an hourly rebuild unnecessarily. With a signed-delta upsert, no lock is needed — the trigger atomically increments the counter row without any application-level serialisation.
    - **Plain English:** Same problem as the daily version, but on an hourly whiteboard. Every hour's tally gets erased and recalculated from scratch even if only one booking happened. The lock that prevents two people from erasing the board at once also accidentally blocks the daily tally from being updated — two unrelated chores stuck waiting on each other.
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
            ->select([...])
            ->groupBy('e.currency_code')
            ->get();
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CACHE-3** · P2 — Weak cache: SiteCacheService::getSiteLinkBlocks uses bare Cache::remember without single-flight lock
    - **Where:** app/Services/Cache/SiteCacheService.php (final method `getSiteLinkBlocks`)
    - **Affects:** Frontend public site rendering — block lists read on every page load. Stampede risk after deploy or mass cache eviction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::remember` with `$this->cacheLock->rememberLocked(...)` (inject `CacheLockService` into `SiteCacheService`).
        - Apply ±20% TTL jitter and enable SWR via `rememberLocked`.
    - **Technical:** `getSiteLinkBlocks` uses plain `Cache::remember` with a 15-minute TTL and no lock. On a cold cache (post-deploy, post-flush), every concurrent request hitting a popular site will independently run the `Block::query()…->get()` Eloquent query — a classic stampede. The project's canonical `CacheLockService::rememberLocked` exists precisely for this scenario and is already used in `AnalyticsCacheService`, `ProfessionalCacheService`, and the analytics controller. The fix is a one-line swap plus injecting the dependency.
    - **Plain English:** When the cache is empty (after a restart or deploy), every visitor to a site triggers their own database query to rebuild the same block list. If 100 people visit at the same moment, that's 100 identical queries. The tool to prevent this — a "wait your turn" lock — already exists in the codebase, it just wasn't wired in here.
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
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CACHE-4** · P2 — Weak cache: StaffStatsController::show has zero caching on the ops dashboard
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php:11-44
    - **Affects:** Staff ops dashboard — every page load hits three aggregate queries (professional counts, active subscriptions, pending commission sum). Low traffic now, but no headroom.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the three aggregate queries in `CacheLockService::rememberLocked` with a short TTL (60s) + jitter + SWR.
        - Push-invalidate the cache key from any staff write path that changes professional counts, subscription state, or commission status (or use a short-enough TTL that staleness is acceptable — 60s is fine for an ops dashboard).
    - **Technical:** The `show` method performs three aggregate queries on every request with no caching layer: `COUNT(*) … GROUP BY professional_type` over `core.professionals`, `COUNT(*)` over `billing.subscriptions`, and `SUM(amount_cents)` over `commerce.commission_movements`. While staff dashboard traffic is low at pre-beta, the pattern is fragile — any automated monitoring or polling will multiply the load. The canonical replacement is `rememberLocked` with a 60s TTL, which would collapse N requests into 1 DB hit per minute. No push-invalidation is strictly needed at this traffic level; the TTL alone bounds staleness acceptably.
    - **Plain English:** The staff dashboard runs three "count everything" database queries every time someone opens the page. Right now that might be once an hour. But if someone sets up a monitoring tool that refreshes every 30 seconds, or if the team grows, those queries pile up. A 60-second sticky-note cache would solve this — the first person asks the DB, everyone else reads the sticky note for the next minute.
    - **Evidence:**
        ```php
        public function show(Request $request): JsonResponse
        {
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
            // ... return $this->success([...])
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-5** · P2 — Weak cache: SiteCacheService::getPublicSitePayload uses hardcoded 15-min TTL with no jitter
    - **Where:** app/Services/Cache/SiteCacheService.php (three `Cache::put($key, …, now()->addMinutes(15))` calls within the method)
    - **Affects:** Public site payload — 95% of frontend traffic. Synchronised expiry can produce thundering-herd rebuilds across the fleet.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Port `getPublicSitePayload` to use `CacheLockService::rememberLocked` instead of the hand-rolled `Cache::lock` + `Cache::put`. This gets jitter, SWR, and consistent lock semantics for free.
        - If keeping the manual lock approach, apply ±20% jitter to the 15-min TTL at each `Cache::put` call site.
    - **Technical:** The method implements single-flight correctly with `Cache::lock('site:fill:'.$subdomain, 10)` — so cold-cache stampede is handled. However, the TTL is a flat `now()->addMinutes(15)` at every write site. If a deploy or `Cache::flush()` clears all site payloads simultaneously, all subsequent requests will rebuild at staggered times (thanks to the lock), but the *next* expiry cycle 15 minutes later will see all keys expire at the same instant, creating a synchronised wave of rebuilds. Jitter (±20%) spreads those expiry times across a 12–18 minute window. The project's `CacheLockService::writeWithJitter` already implements this for int TTLs.
    - **Plain English:** Every site's cached homepage data expires exactly 15 minutes after it was built. If a bunch of sites were all rebuilt at the same time (say, after a server restart), they'll all expire at the same moment and trigger a wave of simultaneous rebuilds. Adding a random ±3-minute wiggle to each expiry time spreads that wave out so the system doesn't get a spike of work every 15 minutes.
    - **Evidence:**
        ```php
        Cache::put($key, $cached, now()->addMinutes(15));  // cached hit, healed
        // ...
        Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(30));  // negative cache
        // ...
        Cache::put($key, $data, now()->addMinutes(15));  // fresh build
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CACHE-6** · P3 — Weak cache: ProfessionalAnalyticsController::summary passes DateTimeInterface TTL, bypassing jitter
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php (the `$cacheTTL` variable and `rememberLocked` call around lines 55–57)
    - **Affects:** Professional dashboard — "today" queries expire in lockstep every 5 minutes; historical queries expire in lockstep every 24 hours.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Switch to integer-second TTLs (e.g., `300` for 5 min, `86400` for 24h) so `writeWithJitter` applies ±20% spread.
        - Or extend `writeWithJitter` to accept a `DateTimeInterface` and compute seconds-until-deadline with jitter applied.
    - **Technical:** The controller computes `$cacheTTL = $to->isToday() ? now()->addMinutes(5) : now()->addHours(24)` and passes a `DateTimeInterface` to `rememberLocked`. Inside `CacheLockService::writeWithJitter`, `DateTimeInterface` TTLs skip the jitter branch entirely and write an exact deadline. For "today" queries, every dashboard user who loads at the same wall-clock moment produces keys that all expire at the same instant 5 minutes later. For historical queries, a nightly batch or a deploy that warms caches at the same time creates synchronised expiry 24 hours later. The fix is trivial: use `300` and `86400` integer TTLs so the existing jitter logic activates.
    - **Plain English:** The dashboard cache for "today" expires exactly 5 minutes after it's created. If three team members all load the dashboard at 9:00 AM, all their caches expire at exactly 9:05 AM — and all three trigger a rebuild simultaneously. A small random offset (±1 minute) would spread those rebuilds out so they don't all hit the database at the same instant.
    - **Evidence:**
        ```php
        $cacheTTL = $to->isToday() ? now()->addMinutes(5) : now()->addHours(24);
        $data = $this->cacheLock->rememberLocked($cacheKey, $cacheTTL, function () use (...) {
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CACHE-7** · P2 — Fan-out: FanOutBrandStatusNotificationJob dispatches one child job per affiliate in an unbounded foreach
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:52-61
    - **Affects:** Every brand status transition (live → building, building → live, systems_down) — dispatches `SendBrandStatusNotificationJob` for every connected affiliate. At 30 brands × 50 affiliates = 1,500 jobs per simultaneous status change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Batch child dispatches using Laravel's `Bus::batch()` or collect affiliate IDs and pass the array to a single job that iterates them internally.
        - For low-urgency notifications like brand status changes, add a short delay (`delay(now()->addSeconds(rand(1, 30)))`) to each child job to avoid queue thundering herd.
    - **Technical:** The job chunks by 500 rows (good for memory) but then `foreach`-dispatches individual `SendBrandStatusNotificationJob` instances with no batching. Each dispatch writes to Redis (the queue driver) — at 50 affiliates this is negligible, but the pattern is unbounded. If a brand with 500+ affiliates changes status, 500 jobs hit Redis in a tight loop. The canonical replacement is a single batched job or passing the full affiliate list to a handler that iterates internally, reducing Redis round-trips from O(N) to O(1). The `InviteExpirySweepJob` in the same directory demonstrates the alternative — it processes rows in-chunk and handles notifications inline without fanning out to child jobs.
    - **Plain English:** When a brand changes its program status (going live, pausing, etc.), the system sends a notification to every connected affiliate. Right now it does this by creating a separate "send this one notification" task for each affiliate — like writing 50 individual Post-it notes instead of one list. At 50 affiliates this is fine; at 500 it becomes a blizzard of tasks hitting the queue at once.
    - **Evidence:**
        ```php
        DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->chunkById(500, function ($rows) use ($brandName, $yearWeek) {
                foreach ($rows as $row) {
                    SendBrandStatusNotificationJob::dispatch(
                        affiliateProfessionalId: $row->affiliate_professional_id,
                        // ...
                    );
                }
            });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-8** · P2 — Fan-out: SendStaffBroadcastEmailsJob dispatches one child job per email subscriber in an unbounded foreach
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:44-52
    - **Affects:** Staff broadcast emails — every platform-wide announcement fans out `SendStaffBroadcastEmailToSubscriberJob` per subscriber. Unbounded as subscriber list grows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace per-subscriber child jobs with chunked inline processing: within each 500-row chunk, iterate and send emails directly (with try/catch per recipient) rather than dispatching a job per recipient.
        - If per-recipient isolation is required (independent retries), use `Bus::batch()` with chunking so the queue receives batches of ~100 rather than N individual jobs.
        - Add a rate-limiter or `delay()` spread to avoid overwhelming the mail driver.
    - **Technical:** Unlike `FanOutBrandStatusNotificationJob` (bounded by affiliates-per-brand ~50), this job's fan-out is unbounded — every `EmailSubscription` with `status = 'subscribed'` and matching `list_key` gets a dedicated job. At 10,000 subscribers, that's 10,000 jobs pushed to the `mail` queue in a tight `foreach` loop. Each job instantiation writes to Redis, and Horizon must deserialize and dispatch each one. The canonical replacement for broadcast mail is chunked inline processing: within the `chunkById` callback, loop over subscribers and send mail directly, catching failures per-recipient so one bad address doesn't block the chunk. The `SendStaffBroadcastEmailToSubscriberJob` can still exist as the unit of retry if individual isolation matters — but dispatch them in batches via `Bus::batch()` rather than a flat foreach.
    - **Plain English:** Sending a platform announcement to 10,000 subscribers currently creates 10,000 individual "send this one email" tasks all at once. It's like hiring a separate courier for each letter instead of giving one courier a bag of letters. Each courier costs overhead (Redis writes, queue deserialization), and 10,000 couriers arriving at the post office simultaneously creates a traffic jam.
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
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CACHE-9** · P2 — Write amplification: InviteExpirySweepJob updates each expired invite individually instead of batching
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:35-62
    - **Affects:** Scheduled invite expiry — each expired invite generates one individual UPDATE plus one notification publish. Under a bulk CSV import that expires simultaneously, N individual write operations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect expired invite IDs within the chunk, issue a single `UPDATE … WHERE id IN (…)` for the status change, then iterate only for notification publishing.
        - If notifications must be individual (different `dedupeKey` per invite), that's acceptable — but the UPDATE should be batched.
    - **Technical:** The job chunks by 500 rows, then foreach-row issues an individual `UPDATE` setting `status = 'expired'` and publishes a notification. The `UPDATE` is guarded by `WHERE status = 'pending'` (good for idempotency), but issuing 500 individual UPDATEs when one `WHERE id IN (…)` would suffice is classic write amplification. Each UPDATE is a separate Postgres round-trip and WAL entry. The notification publish is inherently per-row (different dedupe keys), but the status update can be batched: collect IDs, `UPDATE … SET status = 'expired' WHERE id IN (…) AND status = 'pending'`, then iterate for notifications.
    - **Plain English:** When invite expiry runs nightly, it processes expired invites in batches of 500. But for each expired invite, it sends a separate database "mark as expired" command — like updating a spreadsheet one cell at a time instead of highlighting all expired rows and updating them together. The database has to process 500 separate update commands instead of one. The notifications still need to be sent individually (each has its own message), but the status update can be done in one shot.
    - **Evidence:**
        ```php
        foreach ($chunk as $invite) {
            try {
                DB::table('brand.brand_affiliate_invites')
                    ->where('id', $invite->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired', 'updated_at' => $now]);
                // ... then publish notification per invite
            } catch (\Throwable $e) { ... }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-10** · P2 — Hot-path heavy work: CommerceNotificationService::notifyBookingMilestonesForProfessional runs full COUNT+SUM over booking_events per booking completion
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:120-157
    - **Affects:** Every booking completion triggers a full-table aggregate scan of `analytics.booking_events` for that professional — even though the milestone check only fires for 6 specific count thresholds and 6 revenue thresholds.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cache the running totals in `ProfessionalCacheService` (or a dedicated counter on `core.professionals`) incremented atomically on each booking event.
        - Or maintain a counter row in a `booking_milestone_state` table updated via trigger on `analytics.booking_events`, read directly instead of re-aggregating.
        - At minimum, add `CacheLockService::rememberLocked` with a short TTL (60s) so the aggregate query only runs once per minute per professional.
    - **Technical:** On every booking completion, `notifyBookingMilestonesForProfessional` runs `SELECT COUNT(*), COALESCE(SUM(amount_paid_cents), 0) FROM analytics.booking_events WHERE professional_id = ?` — a full scan of all booking events for that professional. At 100 bookings/year, this is trivial. At 1,000 bookings, it's a growing sequential scan per booking. The milestone check only needs to know if the count crossed 1, 5, 10, 25, 50, or 100 — 6 threshold values — yet it recalculates the total every time. The canonical replacement is a cached running total (in `ProfessionalCacheService` or a dedicated counter column) that gets incremented atomically on booking event creation, making the milestone check O(1) instead of O(N).
    - **Plain English:** Every time a booking is completed, the system counts ALL of that professional's bookings from the beginning of time to check if they've hit a milestone (like "100th booking!"). It's like counting all the coins in your piggy bank from scratch every time you add one, just to see if you've hit $100. A running tally — adding 1 to a counter each time — would give the same answer instantly without recounting everything.
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
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CACHE-11** · P3 — Observer side-effect: EmailSubscription::booted runs a Customer lookup + saveQuietly on every subscription save
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php (`booted` method, saved hook)
    - **Affects:** Any save to `notifications.email_subscriptions` (subscribe, unsubscribe, import) triggers a `Customer` lookup + update to sync `marketing_opt_in_cached`. Under bulk CSV import, N additional queries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `Customer` cache sync to a queued job (`SyncCustomerMarketingOptInJob`) dispatched from the observer, so the write path isn't delayed by an extra query.
        - Or, if the Customer cache sync must be synchronous, add an in-memory "already synced" check per request cycle to avoid double-syncing if the same subscription is saved twice.
    - **Technical:** The `saved` observer hook runs `Customer::query()->where(…)->first()` and `$customer->saveQuietly()` on every `EmailSubscription` save. For a bulk import of 1,000 subscribers, that's 1,000 extra DB queries on the import thread. At pre-beta scale this is fine, but the pattern sets a ceiling. The canonical approach in this codebase is `afterCommit = true` observers dispatching a queued job — `BrandProfileObserver` demonstrates this by dispatching `FanOutBrandStatusNotificationJob` rather than doing the work inline. The Customer cache sync is not urgent (it's a UX optimisation for the contact list view), so a queued job is appropriate.
    - **Plain English:** Every time someone subscribes or unsubscribes from marketing emails, the system immediately runs an extra database query to update a cached "opted in?" flag on the Customer record. For a single person clicking "subscribe," this is instant. But if someone imports a spreadsheet of 1,000 subscribers, the system does 1,000 extra lookups during the import. Moving that update to a background job means the import finishes faster and the cache gets updated a few seconds later — which is fine for a display flag.
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
    - `[DRAFT, confidence: 0.70]`

- [ ] **#CACHE-12** · P3 — Hot-path fan-out: ServiceObserver dispatches up to 2 external sync jobs per service save
    - **Where:** app/Observers/Core/ServiceObserver.php:31-47 (`runHooks` calling `dispatchSquareSync` and `dispatchFreshaSync`)
    - **Affects:** Every service create/update/delete/restore may dispatch `PushServiceToSquareJob` and/or `PushServiceToFreshaJob`. Under bulk import of 50 services, up to 100 sync jobs hit the queue.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Batch sync dispatches: collect affected service IDs in the observer, then dispatch a single `BatchSyncServicesJob` after the request completes (using `dispatchAfterResponse()` or a terminable middleware).
        - Or coalesce: if a professional saves 5 services in one request, dispatch one sync job with all 5 IDs instead of 5 separate jobs.
    - **Technical:** The `saved`/`deleted`/`restored` hooks call `dispatchSquareSync` and `dispatchFreshaSync`, each conditionally dispatching a job on the `integrations` queue. For a single service edit, this is 0–2 jobs — fine. For a bulk CSV import of 50 services, this is up to 100 jobs pushed in a tight loop. Each `PushServiceTo*Job` likely makes an external API call (Square/Fresha), so rate-limiting is also a concern. The canonical approach is to coalesce: in the observer, collect IDs into a static array and dispatch a single batch job in the `__destruct` or a terminable callback. Alternatively, debounce with a short delay so multiple saves within a few seconds get merged into one sync.
    - **Plain English:** When someone edits a service (like changing a price or description), the system can fire off two background sync jobs — one to Square, one to Fresha — to keep those external platforms up to date. For one edit, that's fine. But if someone bulk-imports 50 services, that's potentially 100 sync jobs fired at once, all racing to call Square and Fresha's APIs. Batching those into one "sync these 50 services" job is more efficient and less likely to hit rate limits.
    - **Evidence:**
        ```php
        if ($this->shouldDispatchSquareSync($pro)) {
            $this->dispatchSquareSync($service->id, $action);
        }
        if ($this->shouldDispatchFreshaSync($pro)) {
            $this->dispatchFreshaSync($service->id, $action);
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#CACHE-13** · P2 — Aggregate tables possibly orphaned: booking_metrics_hourly and booking_metrics_daily are written by rebuild jobs but no read path found in the provided dashboard controllers
    - **Where:** analytics.booking_metrics_hourly and analytics.booking_metrics_daily (written by `BookingAnalyticsAggregateService`, read path unknown)
    - **Affects:** If these tables are truly orphaned, the rebuild jobs are dead weight consuming queue time, DB writes, and storage. If they ARE read somewhere (e.g., a booking-specific dashboard not in the audit scope), the read path should be evaluated as a Category 4 candidate for conversion to live queries.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Audit the codebase for any SELECT against `analytics.booking_metrics_hourly` or `analytics.booking_metrics_daily`. If none exist, drop the tables and delete the rebuild jobs as part of the next Phase cleanup (following the Phase 4 precedent).
        - If they ARE read, evaluate whether the read query can be replaced with a live `SUM`/`COUNT` over `analytics.booking_events` grouped by `DATE_TRUNC`. At 100 bookings/affiliate/year, a live query with an index on `(professional_id, occurred_at)` is sub-millisecond — no aggregate table needed.
    - **Technical:** The `ProfessionalAnalyticsController::summary` method (the primary analytics dashboard endpoint in the audit scope) reads from `analytics.site_visits`, `analytics.link_clicks`, `commerce.orders`, and `analytics.cart_events` — but never from `booking_metrics_hourly` or `booking_metrics_daily`. The rebuild jobs (`CACHE-1`, `CACHE-2`) faithfully maintain these tables, but if nothing reads them, the work is pure overhead. Phase 4 of the commerce rebuild already dropped legacy aggregate tables (`RebuildSite*AggregatesJob` references removed). These booking tables may be the next candidate. If a booking dashboard does exist outside the audit scope, the tables fall under Category 4 — a per-day rollup that always equals `SUM(booking_events.amount_paid_cents) WHERE professional_id = ? AND DATE(occurred_at) = ?` can be replaced with an indexed live query + `rememberLocked` cache.
    - **Plain English:** The system has two tally boards for booking stats (one per hour, one per day) that get carefully updated by background jobs. But looking at the main dashboard code, nothing actually reads from those tally boards — the dashboard goes straight to the raw booking events instead. It's like keeping a meticulously updated spreadsheet that nobody opens. Before fixing the tally system (see findings #1 and #2), first check whether anyone actually looks at the tallies. If not, the tally boards and the work to maintain them can be removed entirely.
    - **Evidence:**
        ```php
        // These tables are WRITTEN by:
        DB::table('analytics.booking_metrics_hourly')->insert($inserts);
        DB::table('analytics.booking_metrics_daily')->insert($inserts);

        // But ProfessionalAnalyticsController::summary reads from:
        DB::table('analytics.site_visits')  // visits
        DB::table('analytics.link_clicks')  // clicks
        DB::table('commerce.orders')        // commerce
        DB::table('analytics.cart_events')  // cart events
        // No reference to analytics.booking_metrics_*
        ```
    - `[DRAFT, confidence: 0.65]`
