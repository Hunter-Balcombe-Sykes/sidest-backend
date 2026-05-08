- [ ] **#CHAIN-1** · P2 — CreateShopifySalesChannelJob is absent from the documented Shopify install job chain
    - **Where:** app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php:15-18 (docblock); app/Jobs/Shopify/CreateShopifyCollectionsJob.php:377-415 (findPublicationId)
    - **Affects:** Brands connecting Shopify — the app's own sales channel publication is never created, so collections always fall back to "Online Store."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Insert `CreateShopifySalesChannelJob::dispatch($this->integrationId)` into the install chain *before* CreateShopifyMetafieldsJob, so the publication exists when collections are published.
        - Remove or update the `findPublicationId` fallback heuristic in CreateShopifyCollectionsJob once the channel job is wired, to avoid silent fallback masking future regressions.
    - **Technical:** The docblock in CreateShopifyAffiliateDiscountJob explicitly documents the full install chain: `ShopifyIntegrationController → CreateShopifyMetafieldsJob → CreateShopifyCollectionsJob → CreateShopifyAffiliateDiscountJob → BackfillBrandHasEnabledVariantsJob`. CreateShopifySalesChannelJob is not in that list. Meanwhile `CreateShopifyCollectionsJob::findPublicationId()` searches for a publication named after the app (created by CreateShopifySalesChannelJob) and falls back to "Online Store" when not found. Since the job that creates that publication is never dispatched, the fallback is always taken — the sales channel job file exists but its output is never consumed. The feature degrades gracefully but the intended architecture (app-owned publication for collection publishing) is dead.
    - **Plain English:** The Shopify setup has a checklist of things to create: metafields, collections, discounts, etc. One item — "create our own sales channel" — has code written for it, but nobody ever puts it on the to-do list. Every brand silently falls back to Shopify's default "Online Store" channel instead. It works, but the custom channel code is shelfware.
    - **Evidence:**
        ```php
        // CreateShopifyAffiliateDiscountJob.php, lines 15-18:
        // Dispatch order:
        //   ShopifyIntegrationController → CreateShopifyMetafieldsJob →
        //     CreateShopifyCollectionsJob →
        //       CreateShopifyAffiliateDiscountJob →
        //         BackfillBrandHasEnabledVariantsJob (this, last in the chain).
        ```
        ```php
        // CreateShopifyCollectionsJob.php, findPublicationId() fallback:
        if ($onlineStoreId !== null) {
            return $onlineStoreId;
        }
        if (! empty($edges)) {
            Log::warning('No suitable publication found for collection publishing', [...]);
        }
        return null;
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#INTEG-1** · P3 — Fresha and Square feature-flag checks are duplicated in observer and job, causing no-op dispatches when config drifts
    - **Where:** app/Observers/Core/ServiceObserver.php:136-147 (shouldDispatchFreshaSync) and app/Jobs/Fresha/PushServiceToFreshaJob.php:35-37 (handle guard)
    - **Affects:** Queue throughput — jobs are dispatched to Redis and picked up by workers only to exit immediately if the feature flag was toggled between dispatch and execution.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pick one gatekeeper: either the observer's `shouldDispatch*` methods OR the job's `handle()` early-return, not both.
        - Keep the observer gate (avoids dispatching dead jobs) and remove the redundant `config('partna.features.fresha_sync')` checks from PushServiceToFreshaJob and PushServiceToSquareJob handle() methods.
    - **Technical:** ServiceObserver already gates dispatch on `partna.features.fresha_sync` and integration presence before queuing PushServiceToFreshaJob. The job's handle() then checks the same config flag again and returns early if disabled. In normal operation the second check never fires — but it means every Fresha/Square push job carries a useless config read and branch. Worse, if the feature is enabled at dispatch time but disabled before the job runs (config deploy), the job silently no-ops with no log — masking the fact that a service mutation was dropped. The same pattern exists for Square (`PushServiceToSquareJob`).
    - **Plain English:** There's a bouncer at the club door checking IDs, and then another bouncer inside the empty club checking them again. The second bouncer never actually stops anyone who wasn't already stopped — they're just standing there. If the club rules change between the door and the dance floor, someone could get let in and then quietly shown out with no record.
    - **Evidence:**
        ```php
        // ServiceObserver.php — already gates dispatch:
        private function shouldDispatchFreshaSync(?Professional $professional): bool
        {
            if (! (bool) config('partna.features.fresha_sync', false)) {
                return false;
            }
            // ...
        }
        ```
        ```php
        // PushServiceToFreshaJob.php — same check again:
        public function handle(FreshaServiceSyncService $syncService): void
        {
            if (! (bool) config('partna.features.fresha_sync', false)) {
                return;
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#INTEG-2** · P3 — Fresha and Square catalog delta sync jobs are fully feature-gated with no dispatch path visible in the provided observer
    - **Where:** app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php and app/Jobs/Square/SyncSquareCatalogDeltaJob.php (entire files)
    - **Affects:** Booking-integration brands using Fresha or Square — catalog delta syncs may never run if dispatch is only wired through code not shown here (e.g., scheduled commands).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm where SyncFreshaCatalogDeltaJob and SyncSquareCatalogDeltaJob are dispatched (likely a scheduled cron or webhook handler not included in this audit).
        - If there is no dispatch site, either wire them into the scheduler or delete the job files to reduce maintenance surface.
    - **Technical:** Unlike PushServiceToFreshaJob (dispatched by ServiceObserver), the catalog delta sync jobs (`SyncFreshaCatalogDeltaJob`, `SyncSquareCatalogDeltaJob`) have no dispatch site visible in the provided files. The observer handles per-service push syncs, but full/delta catalog pulls from third-party platforms are a different concern. If these are only dispatched by an artisan command or scheduled task not included here, they may be dead code. Both jobs also carry the same redundant feature-flag check noted in INTEG-1. If the Fresha/Square features are disabled in production, these four job classes represent maintenance burden with zero runtime value.
    - **Plain English:** These jobs are like having a delivery truck parked in the garage but nobody has the keys on their keyring. The truck might be used by a scheduler we can't see from here, or it might just be taking up space. If Fresha and Square aren't live features yet, these four job files are dead weight every developer has to read past.
    - **Evidence:**
        ```php
        // SyncFreshaCatalogDeltaJob.php — feature-gated, no observer dispatch:
        public function handle(FreshaServiceSyncService $syncService): void
        {
            if (! (bool) config('partna.features.fresha_sync', false)) {
                return;
            }
            // ...
        }
        ```
        ```php
        // SyncSquareCatalogDeltaJob.php — same pattern:
        public function handle(SquareServiceSyncService $syncService): void
        {
            if (! (bool) config('partna.features.square_sync', false)) {
                return;
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SCHED-1** · P3 — InviteExpirySweepJob, NudgeStuckOnboardingJob, SendWeeklyAnalyticsNotificationJob, CheckStreamingLiveStatusJob, VoidExpiredPayoutsJob, and ProcessCommissionPayoutsJob have no dispatch sites visible in the provided files
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php, app/Jobs/Notifications/NudgeStuckOnboardingJob.php, app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php, app/Jobs/Streaming/CheckStreamingLiveStatusJob.php, app/Jobs/Stripe/VoidExpiredPayoutsJob.php, app/Jobs/Stripe/ProcessCommissionPayoutsJob.php (entire files)
    - **Affects:** Cron-driven features (invite expiry, onboarding nudges, weekly analytics, streaming status, payout voiding, commission processing) — if the scheduler entries are missing, these features are silently broken.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Cross-check `app/Console/Kernel.php` schedule() method to confirm each of these six jobs has a corresponding `$schedule->job()` entry.
        - Document the mapping in each job's class docblock: "Scheduled in Kernel: daily at 03:00" or similar, so future audits don't flag them.
    - **Technical:** These six jobs have zero dispatch sites in the provided files — no observer, controller, action, or other job chains into them. They are almost certainly scheduled via Laravel's task scheduler in `App\Console\Kernel`, which was not included in this audit's file list. Without confirmation they're registered, they represent a risk: a refactor that accidentally drops a scheduler line would produce zero test failures (jobs still exist) but silently break production behavior. The `VoidExpiredPayoutsJob` docblock even hints at this history — "Without this job the per-payout void_at column was being written but never enforced — the older 30-day ledger-entry void path is unrelated and operates one layer below."
    - **Plain English:** These are the automatic-timer jobs — the ones that should fire on a schedule like "every morning" or "every two minutes." From the files we can see, nobody ever pushes the "go" button on them. They're almost certainly wired into Laravel's clock (the scheduler file we can't see), but if that wiring ever came loose, these features would silently stop working with no error messages. Each job should carry a note saying exactly where its alarm clock is set.
    - **Evidence:**
        ```php
        // InviteExpirySweepJob.php — no constructor args, clearly cron-driven:
        public function __construct()
        {
            $this->onQueue('notifications');
        }
        ```
        ```php
        // VoidExpiredPayoutsJob.php — even references a past bug from missing enforcement:
        // Without this job the per-payout void_at column was being written but never
        // enforced — the older 30-day ledger-entry void path is unrelated and operates
        // one layer below. Closes #CR-003.
        ```
    - `[DRAFT, confidence: 0.75]`
