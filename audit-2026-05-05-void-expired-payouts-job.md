# VoidExpiredPayoutsJob Correctness Audit — 2026-05-05

**Branch:** `development-v2`
**Lens:** VoidExpiredPayoutsJob correctness — race safety, schema invariants, scheduling, observability
**Pipeline:** Manual review (Claude Opus 4.7) — DeepSeek scan tier was unreachable at the time of this audit (TLS reset on `api.deepseek.com`); user opted out of the pipeline this once and authorised a hand-rolled audit.
**Source files audited:**
- `app/Jobs/Stripe/VoidExpiredPayoutsJob.php` (new)
- `app/Services/Stripe/CommissionVoidService.php` — specifically `processExpiredPayouts` + `cancelExpiredPayout` (lines 105–189, new)
- `tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php` (new, 5 cases)
- `routes/console.php:66–74` (schedule registration, new)

**Cross-referenced for context:**
- `app/Services/Stripe/CommissionPayoutService.php` (writers of `void_at` / `pending_funds` / `markPendingFunding`)
- `supabase/migrations/20260428000000_payout_grace_and_app_fee.sql` (column + partial index)
- `supabase/migrations/20260403000000_v2_baseline.sql` (status check constraint)
- `supabase/migrations/20260416000000_add_commission_grace_period.sql` (ledger `voided` status)

## Progress

- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 4 complete

**Status:** Implementation is functionally correct and closes the original #CR-003 finding. All five existing tests pass. The findings below are operational and architectural — none of them block the cron from doing its core job. P1 should ship before the dashboard surface goes to users; P2/P3 are post-launch backlog.

---

## P1 — Fix before pilot launch

- [ ] **#VEP-1** · P1 — No "cron ran successfully" heartbeat; healthy days produce silence
    - **Where:** `app/Jobs/Stripe/VoidExpiredPayoutsJob.php:46-48`
    - **Affects:** Operational observability of the nightly job. If the scheduler stops dispatching this job (Horizon outage, cron drift, schedule mis-deploy), there is no positive log signal that distinguishes "ran with nothing to do" from "didn't run at all." The first sign of a stuck cron will be a customer support ticket asking why their 90-day-old payout is still pending.
    - **Effort:** S (~15 min)
    - **What to do:**
        - Move the `Log::info` call out of the conditional so it always emits, regardless of stats. Example:
            ```php
            $stats = $voidService->processExpiredPayouts();
            Log::info('Expired payout void processing complete', $stats);
            ```
        - Optionally promote to `Log::notice` when `$stats['cancelled_count'] > 0` so a real action stands out from the heartbeat. Match the pattern `markPendingFunding` already uses (`Log::notice` for a state change, `Log::info` for a routine pass).
        - Verify the log line appears in Nightwatch's command/job index — if not, also surface it as a custom Nightwatch event so the cadence is visible on the monitoring dashboard.
    - **Technical:** The current code only logs when `cancelled_count > 0 || voided_entries > 0`. Pre-beta, the expected steady state for the next several months is "no expired payouts" — i.e. the job will run for ~60+ days with zero log output before the first real cancellation lands. That's exactly the window in which a silent scheduler regression would go undetected. `Schedule::onFailure` only fires on exception throws, not on "didn't dispatch at all," so it's not a substitute. The lightest fix is unconditional `Log::info`; the more robust fix is to emit a Nightwatch `customEvent('payout_void_cron.complete')` that the dashboard can chart for cadence.
    - **Plain English:** Right now, the cron only writes to the log when it actually cancels a payout. For the first months of operation, when no payouts are expiring, the log file will be empty for this job — which means there's no way to tell whether it ran or not. If the scheduler quietly stopped dispatching it, the first symptom would be a stuck payout in support, not a missing log line. Always log "I ran, here are the stats" — even when stats are zero — so operations can confirm the job is alive.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/VoidExpiredPayoutsJob.php:42-48
        public function handle(CommissionVoidService $voidService): void
        {
            $stats = $voidService->processExpiredPayouts();

            if ($stats['cancelled_count'] > 0 || $stats['voided_entries'] > 0) {
                Log::info('Expired payout void processing complete', $stats);
            }
        }
        ```

---

## P2 — Address before scale or before exposing surface to users

- [ ] **#VEP-2** · P2 — No affiliate-facing notification on void; UX coherence gap
    - **Where:** `app/Services/Stripe/CommissionVoidService.php:154-189` (`cancelExpiredPayout` writes DB only, no `NotificationPublisher::publish` call). The `sendGracePeriodWarnings` flow at lines 192-210 sends day-20/day-28 warnings but they're keyed off `Professional.stripe_grace_period_ends_at` (per-affiliate), not `commission_payouts.void_at` (per-payout) — so the warning ladder isn't aligned with the enforcement deadline.
    - **Affects:** User trust on the affiliate dashboard. The grace summary banner at `AffiliateCommerceAnalyticsController.php:222-307` shows a per-payout countdown driven by `void_at`. When the deadline passes, the payout is silently cancelled and the banner disappears — no in-app notification, no email, no acknowledgement that the affiliate forfeited money. Affiliates who were watching the countdown receive no closure on what happened.
    - **Effort:** M (~2-4h, depends on whether you want one notification per voided payout or a digest)
    - **What to do:**
        - Inject `NotificationPublisher` into `CommissionVoidService` (already done — line 26 of the constructor takes it).
        - In `cancelExpiredPayout` (after the transaction commits), publish one notification per voided payout to the affiliate:
            ```php
            $this->publisher->publish(
                professionalId: $payout->affiliate_professional_id,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Commission payout expired',
                body: sprintf(
                    'Your %s payout from %s has been cancelled because Stripe Connect was not activated within the grace period.',
                    $this->formatMoney($payout->net_payout_cents, $payout->currency_code),
                    $payout->brandProfessional->display_name ?? 'a brand',
                ),
                dedupeKey: "payout_voided.{$payout->id}",
                ctaUrl: '/account/settings?section=stripe',
                retentionConfigKey: 'commission',
            );
            ```
        - Decide explicitly: one-per-payout notification (simple, may spam if many expire on the same night) vs digest (one notification listing all voided payouts for that affiliate that night). For pre-beta, one-per-payout is fine — volume is low enough that spam isn't a concern, and per-payout `dedupeKey` makes it idempotent on retry.
        - Align the warning ladder: replace `sendPerCommissionWarnings` (which warns 5 days before ledger-entry void at the 30-day mark) with a per-payout warning that fires N days before `void_at` for active `pending`/`pending_funds` rows. Otherwise affiliates get warned about the wrong deadline.
        - Add a Pest test asserting the notification publisher is called once per voided payout.
    - **Technical:** The original implementation plan called for an affiliate notification on void; the shipped code chose silent enforcement. There's no architectural objection to silence — but the dashboard explicitly draws attention to the deadline (status `'critical' / 'warning' / 'active' / 'none'` with `daysRemaining`), which makes the silent terminal state inconsistent. The dedupe-key pattern in `NotificationPublisher` already handles re-runs cleanly. Larger architectural concern: the existing `sendGracePeriodWarnings` operates on `Professional.stripe_grace_period_ends_at` and `commission_ledger_entries.created_at + voidWindowDays` — neither is the per-payout `void_at` that this new job actually enforces. So affiliates can be in a state where their day-20 warning fires for one deadline, and a different deadline silently triggers cancellation. The two ladders need to be merged.
    - **Plain English:** The dashboard tells affiliates "X days remaining before this payout expires." When the clock hits zero, the system cancels the payout and erases the banner. The affiliate gets no notification — they just open the dashboard one day and the countdown is gone, along with the money. We should send a notification when we cancel a payout so the affiliate knows what happened (and in retrospect, why those banner warnings mattered). Even worse, the *warnings* the affiliate receives before the deadline aren't tied to this deadline at all — they're tied to a different timer. So the warning, the banner, and the actual enforcement are three separate clocks that don't agree with each other. Pick one and make all three match.
    - **Evidence:**
        ```php
        // CommissionVoidService::cancelExpiredPayout — DB writes only, no publish call
        private function cancelExpiredPayout(CommissionPayout $payout, array &$stats): void
        {
            DB::transaction(function () use ($payout, &$stats): void {
                $updated = CommissionPayout::query()
                    ->where('id', $payout->id)
                    ->whereIn('status', ['pending', 'pending_funds'])
                    ->update([
                        'status' => 'cancelled',
                        'failure_code' => 'grace_expired',
                        'failure_reason' => 'Affiliate did not connect Stripe Connect within the grace period.',
                        'updated_at' => now(),
                    ]);
                // ... ledger entry update ...
                $stats['cancelled_count']++;
                // No $this->publisher->publish(...) anywhere.
            });
        }
        ```

- [ ] **#VEP-3** · P2 — `pending_funds` is a phantom status (pre-existing system bug; this implementation defends against it but the test misleads)
    - **Where:** Schema defines `pending_funds` (`supabase/migrations/20260403000000_v2_baseline.sql:878`), partial index includes it (`20260428000000_payout_grace_and_app_fee.sql:44`), three readers expect it (`AffiliateCommerceAnalyticsController.php:178,263,278`, `BrandAffiliateController.php:208`, `CommissionVoidService.php:127,159`), but no writer ever creates a row with `status='pending_funds'`. The supposed writer is `CommissionPayoutService::markPendingFunding` (line 586) — which actually writes `'status' => 'pending'` (line 589). Confirmed by exhaustive grep across `app/`.
    - **Affects:** The void job's defensive `whereIn('status', ['pending', 'pending_funds'])` is half-dead code — the second array element will never match a real row. The test case `'voids expired payouts in pending_funds status too'` (`VoidExpiredPayoutsJobTest.php:216`) tests a state that doesn't exist in production, because the seed helper inserts `pending_funds` directly. The schema is more nuanced than the application — `pending` covers both "approved entries waiting to be paid" AND "payout failed to fund, retry pending," and the analytics consumers can't distinguish them.
    - **Effort:** M (~2-3h) — the fix is one line in `markPendingFunding` plus a data-migration decision for any rows already labelled `pending` that should be `pending_funds`.
    - **What to do:**
        - In `CommissionPayoutService::markPendingFunding` (line 588), change `'status' => 'pending'` to `'status' => 'pending_funds'`. The function name already implies this; the value is the bug.
        - Verify the eligibility re-dispatch loop in `processEligiblePayouts` (line 58) still picks up `pending_funds` rows for retry — currently it only looks for `whereIn('status', ['pending', 'collecting', 'transferring'])`. After the fix, add `'pending_funds'` to that list, otherwise the next nightly pass won't retry funding-failed payouts.
        - Consider a one-time data migration that re-labels existing `pending` payouts where `failure_code IN ('charge_requires_action', 'charge_failed', 'brand_payment_method_missing', 'affiliate_not_connected')` to `pending_funds`. Pre-beta this is probably a no-op, but worth a sanity query.
        - Update the test seed helper in `VoidExpiredPayoutsJobTest.php` to default to `pending` (the actual common case in prod), and keep the `pending_funds` case as a separate explicit assertion that the post-fix value is enforced too.
        - Add a Pest test in `CommissionPayoutServiceTest.php` asserting `markPendingFunding` writes `'pending_funds'`, not `'pending'`.
    - **Technical:** This bug pre-dates the void job. The void job is correct *because* it defends against both possibilities — the implementation has no functional defect on its own. But the broader system silently treats `pending` and `pending_funds` as the same state, which removes the entire point of having two states. The analytics surfaces (`brand_affiliate_summary.pending_cents`, the grace-summary banner) calculate identical results whether `pending_funds` is used or not, but the categorisation ladder for ops/support is broken — there's no way to ask "show me all payouts blocked on funding" because nothing labels them. The void-job-on-`pending_funds` test case looks meaningful but is fictional.
    - **Plain English:** The database is set up to distinguish two stuck states: "waiting to be processed" and "tried to process but the brand's payment failed." The void job correctly handles both. But the rest of the codebase only ever writes one of them — so the "funding failed" bucket is empty in practice, even though we have queries and tests that assume it has rows. The void job inherits this confusion: it tests the funding-failed path, but that path never produces real data. The fix is one line in a different file, plus a sanity check that the retry loop picks up the corrected status.
    - **Evidence:**
        ```php
        // CommissionPayoutService.php:586-600 — function name says "pending funding", value writes plain "pending"
        private function markPendingFunding(CommissionPayout $payout, string $code, string $reason): void
        {
            $payout->forceFill([
                'status' => 'pending',  // ← this should be 'pending_funds'
                'failure_code' => $code,
                'failure_reason' => $reason,
                'processed_at' => null,
            ])->save();
            // ...
        }

        // tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php:216-228 — tests a state that prod never produces
        it('voids expired payouts in pending_funds status too', function () {
            // pending_funds is the post-charge-failure state — the partial index
            // commission_payouts_void_at_idx covers both pending and pending_funds.
            expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString(), status: 'pending_funds');
            // ...
        });
        ```

- [ ] **#VEP-4** · P2 — `whereHas` Stripe-status check is an unindexed correlated predicate; query-plan concern at scale
    - **Where:** `app/Services/Stripe/CommissionVoidService.php:129-131`
    - **Affects:** Query performance once `commerce.commission_payouts` grows. The partial index `commission_payouts_void_at_idx` covers `(void_at)` for `status IN ('pending', 'pending_funds')`, but the correlated `EXISTS` against `core.professionals.stripe_connect_status` is filterless from the index's perspective. Postgres has to seek the affiliate row for every candidate payout. Negligible at current volume; worth flagging for the volume tier where you'd run more than ~10k expired payouts in a single nightly pass.
    - **Effort:** S (~1h) — only worth doing if/when the query starts showing in slow logs.
    - **What to do:**
        - Option A (lightest): leave as-is. Pre-beta scale doesn't trigger this. Re-evaluate when nightly runs exceed ~5s wall time (visible in Nightwatch slow-jobs).
        - Option B (modest): replace `whereHas` with an explicit `JOIN` so Postgres can plan a hash join across the two tables instead of a correlated subquery per row.
        - Option C (most defensive): pre-fetch the set of affiliate IDs with `stripe_connect_status != 'active'` in one query, then `whereIn('affiliate_professional_id', $inactiveAffiliateIds)` on the payouts query. Costs one extra round-trip but the per-row predicate becomes a single integer set membership, fully covered by an index on `commission_payouts.affiliate_professional_id`.
        - Don't ship an index on `(stripe_connect_status)` alone — too low cardinality (4 values) to be useful.
    - **Technical:** Postgres typically rewrites correlated `EXISTS` into a semi-join, but the planner's choice depends on stats and chunk size. The chunk size of 200 means each chunk's main query has up to 200 candidate rows; the join cost is bounded. The real risk is pathological cases where stats drift (e.g., 99% of expiring payouts are for active-Stripe affiliates that get filtered out, so the index narrows to a tiny set but the cost was already paid). At pre-beta scale this is invisible. Worth measuring once a real production cohort exists.
    - **Plain English:** The query says "find payouts past their deadline AND whose owner hasn't connected Stripe." The first half is fast (there's a dedicated index for it). The second half is slower because we have to look up each affiliate one at a time. Right now the table is small enough that nobody will notice. If the platform grows to a point where thousands of payouts expire each night, this becomes the bottleneck. There are easy fixes when that day arrives.
    - **Evidence:**
        ```php
        // app/Services/Stripe/CommissionVoidService.php:126-133
        CommissionPayout::query()
            ->whereIn('status', ['pending', 'pending_funds'])
            ->where('void_at', '<', now())
            ->whereHas('affiliateProfessional', function ($q) {
                $q->where('stripe_connect_status', '!=', 'active');
            })
            ->orderBy('void_at')
            ->chunkById(200, function ($payouts) use (&$stats): void { /* ... */ });
        ```

---

## P3 — Cleanup / quality-of-life

- [ ] **#VEP-5** · P3 — `orderBy('void_at')` before `chunkById` is silently overridden
    - **Where:** `app/Services/Stripe/CommissionVoidService.php:132-133`
    - **Affects:** Code readability. The intent reads as "process oldest deadlines first" — it isn't. Laravel's `chunkById` reorders by the chunk key (default primary key, `id`) regardless of upstream `orderBy` calls.
    - **Effort:** S (~5 min)
    - **What to do:**
        - Drop the `->orderBy('void_at')` line entirely. The `chunkById` call orders by `id`, which is fine because each payout is processed independently — there's no inter-row ordering invariant to preserve.
        - If "oldest deadlines first" is actually wanted (so high-pressure cases are cancelled first), use `chunk(200)` with explicit `orderBy('void_at')` instead. Trade-off: `chunk()` is less safe under concurrent inserts because it offsets by index rather than by ID — for this query that's acceptable since the row set under a given `now()` snapshot is bounded.
        - Pick one and update the inline comment to match.
    - **Technical:** `chunkById` calls `enforceOrderBy()` and replaces user-supplied ordering with `orderBy id`. The orderBy on `void_at` is silently dropped. Not a correctness bug — each payout is processed in isolation and the `cancelExpiredPayout` transaction is independent of any other row — but the code reads like priority ordering and that's misleading.
    - **Plain English:** The code looks like it's saying "process the most overdue payouts first," but the chunk loop ignores that hint and just goes by primary key. So the order is effectively random. The fix is either to delete the misleading line or switch to a different chunk method that actually honours the ordering.
    - **Evidence:**
        ```php
        ->orderBy('void_at')           // <- silently overridden
        ->chunkById(200, function ($payouts) use (&$stats): void { /* ... */ });
        ```

- [ ] **#VEP-6** · P3 — No test for the optimistic-lock race
    - **Where:** `tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php` — no test simulates concurrent status mutation between SELECT and UPDATE.
    - **Affects:** Confidence that the race-protection mechanism actually works. The optimistic lock at line 159 (`whereIn('status', ['pending', 'pending_funds'])` on UPDATE) is the only protection against `ExecuteCommissionPayoutJob` advancing a payout to `collecting` while we're processing it. If that filter were ever silently broken (e.g., someone widens it to include `'collecting'` thinking they're being defensive), the test suite wouldn't catch it.
    - **Effort:** S (~30 min)
    - **What to do:**
        - Add a test that:
            1. Seeds an expired payout in `pending` status.
            2. Mocks the `CommissionPayout::query()->where('id', $id)->whereIn('status', ['pending', 'pending_funds'])->update(...)` to return 0 (simulating the race outcome).
            3. Asserts the ledger entries were *not* voided (no orphan write) and the log message was emitted.
        - Or, simpler: before invoking `processExpiredPayouts`, mutate the seeded row's status to `'collecting'` directly in the DB, then assert the run leaves both the payout and ledger entries untouched.
    - **Technical:** This is the kind of guard you want explicit test coverage for, because its failure mode is silent (no exception, just incorrect state) and the only invariant ("never void ledger entries belonging to a payout that's already in flight") isn't surfaced anywhere else.
    - **Plain English:** The implementation has a safety check: "if another process grabbed this payout while we were thinking about it, back off." That check works, but there's no test proving it works. Add a small test that simulates the race so we'd catch a future change that accidentally weakens the check.
    - **Evidence:** N/A — finding is about an absent test.

- [ ] **#VEP-7** · P3 — No multi-payout / chunk-boundary test
    - **Where:** All five existing tests use a single payout. `chunkById(200, ...)` semantics under N>200 payouts are untested.
    - **Affects:** Edge-case confidence. Pre-beta this is theoretical, but the chunk loop's interaction with per-row transactions (commits between chunks) hasn't been exercised.
    - **Effort:** S (~30 min)
    - **What to do:** Add a test that seeds 250+ expired payouts and asserts the cron processes all of them with correct stats. Use a `chunkById(2, ...)` override or seed enough rows to force at least two chunks.
    - **Technical:** ChunkById processes each chunk in its own SELECT, with each row in its own transaction. There's no test asserting that chunk N+1 isn't accidentally re-fetching rows already processed in chunk N (which `chunkById` is designed to prevent via its `id > last_id` cursor). It's almost certainly fine — but explicit coverage costs little.
    - **Plain English:** All current tests check one payout at a time. The cron is supposed to handle hundreds in a single run. Add one test with a few hundred so we know the chunking actually works as intended.
    - **Evidence:** N/A — finding is about an absent test.

- [ ] **#VEP-8** · P3 — Per-row catch loses stack trace; `failure_code = 'grace_expired'` mixes naming style
    - **Where:**
        - `app/Services/Stripe/CommissionVoidService.php:138-141` — chunk-level catch logs `error => $e->getMessage()` without exception class or stack.
        - `app/Services/Stripe/CommissionVoidService.php:162` — `failure_code = 'grace_expired'` doesn't follow the `<subject>_<state>` convention used by adjacent codes (`affiliate_not_connected`, `brand_payment_method_missing`, `transfer_failed_refund_needed`, `charge_requires_action`).
    - **Affects:** Debuggability, code-style consistency. Both are minor.
    - **Effort:** S (~10 min, both)
    - **What to do:**
        - Change the per-row catch to: `Log::error('Failed to cancel expired payout', ['payout_id' => $payout->id, 'exception' => $e]);` (Laravel's exception serialiser will include class + message + trace) — or call `report($e)` to also fan out to Nightwatch.
        - Rename `'grace_expired'` to `'affiliate_not_connected_grace_expired'` (long but consistent) or `'grace_period_expired'` (shorter, still subject-first). Pick whichever you prefer; the only consumer of `failure_code` is human-facing log/UI surfaces, so the choice is purely cosmetic.
    - **Technical:** N/A.
    - **Plain English:** Two small polish items: when a single payout's cancellation fails, the log line only captures the message text, not the stack trace — making it harder to debug the failure. And the failure code `grace_expired` is shorter than its neighbours but inconsistent with their naming pattern. Both are 5-minute fixes.
    - **Evidence:**
        ```php
        // CommissionVoidService.php:138-141
        Log::error('Failed to cancel expired payout', [
            'payout_id' => $payout->id,
            'error' => $e->getMessage(),
        ]);

        // CommissionVoidService.php:162
        'failure_code' => 'grace_expired',
        ```

---

## Summary

The implementation is functionally correct and the test suite proves the happy path, the Stripe-active negative case, the within-grace negative case, the `pending_funds` variant (though see #VEP-3 — that state never occurs in prod), and the completed-payout protection. The optimistic lock pattern is well-chosen, the transaction scope is right, and the schedule placement (07:00, after the 06:00 payout pass) avoids the race I'd been worried about in the original implementation plan.

**The single most consequential finding** is #VEP-1 (always-on heartbeat log). Pre-beta, this cron will run silently for months before the first real cancellation — making the cron's own liveness invisible. One-line fix; ship before the dashboard surface goes live.

**The most architecturally interesting finding** is #VEP-3 (`pending_funds` is a phantom status). It's pre-existing, not introduced by this PR, but the fact that the void job tests for and defensively handles a state that never exists in production tells you the broader payout state machine has more states than the application ever uses. Worth a deliberate "do we collapse `pending_funds` into `pending`, or do we start writing it from `markPendingFunding`?" decision before the system grows.

**The biggest UX coherence gap** is #VEP-2. The dashboard shows a countdown; nothing closes the loop with the affiliate when the deadline arrives. Combined with the warning ladder being keyed off a *different* deadline (`stripe_grace_period_ends_at`, not `void_at`), affiliates can receive misleading warnings followed by silent enforcement on a different timer. Worth aligning before this surface is exposed to real users.
