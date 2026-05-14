# Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline

Find lifecycle / state-machine / vendor-integration bugs of the same shape we just eliminated from the Stripe payout pipeline (commits 2026-05-07 → 2026-05-09 on `worktree-stripe-payout-lifecycle`, audit closed by `audit-2026-05-09-stripe-payout-lifecycle.md`). The patterns below recur anywhere we accept a webhook, dispatch a retry, fire a periodic notification, hold a financial state in flight, or cache a hot read across writes.

The goal is **scalability to hundreds of affiliates without the current pre-pilot footguns**, not just pre-pilot readiness. Findings must justify themselves against the **scale target** below — but a finding that only manifests at scale is still in scope.

This lens is a **sibling** to `scaling-antipatterns.md`. That lens covers rebuild-on-write, write amplification, weak caching, and aggregate-tables-that-should-be-live-queries. **Run this lens for everything else** — race conditions, idempotency, anchor fields, retry loops, in-flight state mutations, vendor API hygiene, log discrimination, policy gating. Findings that overlap with the scaling lens should be emitted under whichever lens is more specific; the adjudicator dedupes.

## Scale target (every finding must justify against this)

- **200 brands** × **50 affiliates/brand** × **~100 orders/affiliate/year** ≈ **1M orders/year**, peak ~**3K orders/day**.
- **~10K daily payout-related job invocations** (cycle retries, grace warnings, reconcile, transfer status checks).
- **~40K daily notifications** at fan-out peak (status transitions, payout warnings, affiliate digests, order events).
- **Webhook delivery is at-least-once** for Stripe and Shopify — duplicates and re-orderings are normal.
- Database is a **single Supabase Postgres primary** (no read replicas). Cache + queue + sessions on **Redis**. Multi-instance Laravel Cloud — in-process state does not survive deploy or scale-up.

## Use the lens prefix `LIFE` for findings

Number them `LIFE-1`, `LIFE-2`, … sequentially across the whole audit, regardless of category.

## Background — what was just fixed (the canonical patterns)

The Stripe payout work shipped a set of patterns that should now be the default everywhere similar shapes exist. Quote the canonical replacement by name in every finding.

- **Race-safe wallet credit** (`5735525`) — `lockForUpdate` + `UNIQUE` constraint + actor-tagged audit row. Pattern: `lockForUpdate` on the row being mutated, idempotency key as a `UNIQUE` constraint on the write, `actor` column populated for AUSTRAC / audit trail.
- **Typed unique-violation catch** (`#STRIPE-3`, `35c6f31`) — `catch (UniqueConstraintViolationException $e)`, never `catch (QueryException $e)` + `str_contains($e->getMessage(), 'UNIQUE')`. The typed catch is version-stable across Postgres releases and constraint renames.
- **Anchor decoupling** (`#STRIPE-4`, `46072a8`) — when retries reset a deadline field (`void_at`), keep a separate `*_started_at` field for warning-window arithmetic. The retry-safety field and the warning-clock field are different concerns and must not share a column.
- **JSONB dedup for periodic notifications** (`af90b2e`) — instead of a separate `*_warnings_sent` table, store a `{T-30: timestamp, T-7: timestamp, T-1: timestamp}` JSONB on the parent row. Dedup is a single read; retry storms cannot double-fire.
- **In-flight cancellation / shrink** (`dcdb3b4`) — refund-during-grace cancels or shrinks the in-flight payout rather than completing it. Pattern: when the source-of-truth set shrinks under an in-flight aggregate, the aggregate must reconcile, not assume the original snapshot is still valid.
- **Daily reconcile of stuck states** (`0de1f2f`) — `ReconcileStuckTransferringPayoutsJob` runs daily, calls the vendor's `Transfer.retrieve`, transitions payouts that the webhook missed. Pattern: any state that depends on a vendor webhook must have a reconcile job that catches missed deliveries.
- **Distinct logs for distinct failure modes** (`#STRIPE-2`, `35c6f31`) — `null` return from `processPayoutBatch` had two meanings (in-flight vs. cancelled); the log conflated them. Pattern: a function with N distinct outcomes needs N distinct log strings, or a typed return so the caller can branch.
- **Vendor API version pinning** (`9a9b107`) — `STRIPE_API_VERSION` env. Pattern: every vendor SDK call goes through a service that pins API version explicitly; vendor auto-upgrades cannot silently break behaviour.
- **Verbatim vendor error capture** (`bf6e46d`) — Stripe error messages stored verbatim on the failing record, not paraphrased into our own copy. Pattern: vendor errors are hand-debuggable evidence; rephrasing destroys signal.
- **Policy over inline role-scoping** (`#STRIPE-1`, `e1109d3`) — `CommissionPolicy::viewOwnPayouts` instead of `if ($role === 'brand') { ->where(...) } else { ->where(...) }` in the controller. Pattern: any "show only my records" query goes through a Policy ability, never inline.
- **Cache lock-timeout fallthrough** (`a8e866d`) — `rememberLocked` falls through to compute on lock timeout instead of throwing. Pattern: cache primitives degrade gracefully under contention; they never become a single point of failure for a hot read.
- **Per-tenant invalidation jitter** (`38ff4fb`) — when a brand edit invalidates per-affiliate caches, the dispatches are jittered. Pattern: any 1→N invalidation dispatched as a synchronous loop is a thundering-herd risk and needs jitter.
- **Stale-twin invalidation** (`f5450d8`) — `bust(:stale)` paired with `bust(:fresh)`. Pattern: every cache key that has a `:stale` SWR twin must invalidate both halves on the write path.
- **Version-keyed cache** (`27c1b7a`) — `analyticsSummaryVersion` increments on settlement; all windowed reads include the version in their key. Pattern: when invalidating individual keys is too brittle, version-key the namespace and bump the version.

## Findings categories

### (1) Idempotency on the write path

- Inserts into ledger / events / receipt / movement tables without a `UNIQUE` constraint backing an idempotency key.
- `INSERT … ON CONFLICT DO NOTHING` missing where the same write may be retried (webhook re-delivery, job retry, observer re-fire).
- `catch (QueryException $e)` + `str_contains` / regex matching on the error message — replace with `catch (UniqueConstraintViolationException $e)` (Laravel 10+).
- Idempotency-key derivation from a non-deterministic field (e.g. `now()->timestamp`) — must be derived from the inbound event ID or a deterministic hash of the payload.
- External API calls (Stripe Transfer, Shopify cart create, Square charge, Cloudflare DNS create, Hydrogen deploy) without an explicit idempotency key passed to the vendor — re-tries cause duplicates.

### (2) Race-safety on read-modify-write

- Reading a balance / counter / state, computing the new value, writing it back — without `lockForUpdate` or an equivalent advisory lock — across two or more statements.
- Wallet credit / debit, commission accumulation, balance reconciliation, counter increments outside `commerce.brand_affiliate_rollup` (which is trigger-maintained and exempt).
- Status transitions that read the current status and write a new one without locking — two webhooks racing produce a torn state.
- Observers that update aggregate columns on the parent in response to child saves — flag if the update is not signed-delta or not done under a row lock.

### (3) Anchor / time-window correctness

- Periodic-warning logic keyed off a field that is mutated by retries (the `#STRIPE-4` shape). Pattern: any "T-N day warning" job that reads a deadline column the retry loop also resets — find any other job with this shape and propose a `*_started_at` decoupling.
- "Send a warning every N days" fan-out without a `notifications_sent` JSONB or equivalent dedup record — re-runs spam users.
- Cron-driven jobs that bucket by `whereBetween('field', [now()+N, now()+N+1])` — confirm the bucket field is the right anchor (start, not deadline) and that the bucket is exclusive (no overlap with the next bucket).

### (4) Reconcile / repair jobs for vendor-driven state

- Any state that transitions in response to a vendor webhook (`transfer.paid`, `order/paid`, `payment.completed`, `domain.verified`) without a sibling reconcile job that catches missed deliveries. Webhooks are at-least-once and *occasionally zero* — production must not rely on webhook delivery for correctness.
- Reconcile jobs that exist but do not log when they catch a missed delivery — silent reconcile is invisible drift.
- Reconcile jobs that re-trigger downstream side-effects on every run (re-emailing on every reconcile pass) — the side-effect path needs its own dedup.
- Long-lived states (`processing`, `transferring`, `pending_funding`) without a "stuck for > N days" alert.

### (5) In-flight aggregate / batch handling

- Any aggregate (payout batch, order group, notification batch, deployment batch) that snapshots a set of source rows at dispatch time and writes the aggregate later — flag if a refund / cancel / removal between snapshot and write is not handled (the `dcdb3b4` shape).
- Mid-flight cancellation paths that return `null` / `false` / a sentinel — confirm the caller distinguishes "cancelled" from "in flight" (the `#STRIPE-2` shape) and logs each distinctly.
- Batch jobs that fail partially and have no "retry only the failed members" path — full re-runs cause double effects on the succeeded members.

### (6) Vendor-integration hygiene

- Any vendor SDK instantiation (Stripe, Shopify, Cloudflare, OpenAI, Mux) without an explicit API version pin (`STRIPE_API_VERSION` is the canonical example).
- Vendor error messages paraphrased into our own copy on the failing record, instead of stored verbatim — debugging requires verbatim error.
- Vendor exception handling that catches the SDK's base exception class and continues silently — every catch must either re-throw, log with full context, or be a typed expected-failure (`UniqueConstraintViolationException`, `Stripe\Exception\InvalidRequestException` subtypes).
- Synchronous vendor calls (Stripe, Shopify, Cloudflare, Hydrogen, Mux) in webhook controllers / observers / Resource classes — any vendor latency propagates to user-facing p99.
- Webhook handlers that don't verify HMAC / signature, don't dedup on the vendor's event ID, or accept payloads larger than a sane bound.
- Retry loops calling vendor APIs without explicit `$tries` and `$backoff` — vendor outage produces a retry storm.

### (7) Authorization & validation hygiene

- Inline role-scoping in controllers: `if ($role === 'brand') { ->where('brand_professional_id', $pro->id) } else { ->where('affiliate_professional_id', $pro->id) }` — replace with a Policy ability that takes the resolved actor + the resource skeleton (the `#STRIPE-1` shape).
- Inline `abort(403, ...)` / `abort_unless(...)` — replace with `$this->authorizeForUser($pro, 'verb', $resource)` against a Policy.
- Inline `validate([...])` calls in controllers — replace with Form Request classes (the `a11feb2` refactor pattern).
- Endpoints that accept a tenant ID from a request param without re-authorizing against the resolved actor.
- 403 where 404 should be — public endpoints must always 404 on missing-or-not-yours; per-tenant endpoints should 404 to prevent enumeration unless the actor has a list-scope ability.

### (8) Cache invalidation & graceful degradation

These are the patterns NOT covered by `scaling-antipatterns.md` (which focuses on read-side weak caching). Focus here is on the **write-path invalidation** discipline.

- `Cache::forget(key)` on the write path without also busting the `:stale` SWR twin (the `f5450d8` shape).
- 1→N per-tenant invalidations dispatched as a synchronous `foreach` — must be jittered (the `38ff4fb` shape).
- Cache invalidation that targets individual keys when a version-key bump would be safer — flag any cache that's invalidated key-by-key from more than three call sites; that's a signal it should be version-keyed.
- `rememberLocked` calls without a fallthrough on lock timeout — under contention, a single hot key becomes a single point of failure (the `a8e866d` shape).
- Cache primitives without per-prefix metrics (the `16a60ee` pattern) — operational blindness on hit/miss SLO.
- Caches busted on "model saved" but not on the upstream config flip that changes the cached value (e.g. brand status flip should bust catalog cache but only model save does).

### (9) Notification fan-out & dedup

- Fan-out jobs that don't dedup on the recipient × event combination — webhook re-delivery → duplicate emails / pushes.
- Periodic warning notifications without a JSONB dedup column on the parent (the `af90b2e` shape) — flag any "send X every N days" loop without it.
- Fan-out that creates one job per recipient (no `Bus::batch()`) at recipient-counts > 100 — Redis pipeline pressure at peak.
- Notification preference checks done inside the fan-out job rather than at fan-out — wasted job dispatches.
- Eager `NotificationReceipt` rows created at fan-out time when lazy-on-read would be cheaper at the scale target above.

### (10) Observability / Nightwatch readiness

- `Log::warning` / `Log::error` calls without `brand_professional_id`, `request_id`, and operation name in context — Nightwatch correlation breaks.
- Exception messages without a discriminator (`'something went wrong'`) — Nightwatch grouping useless.
- Swallowed exceptions: `try { ... } catch (\Throwable $e) { return null; }` without a log emit — Nightwatch never sees it.
- `Log::warning` used as a breadcrumb when an alert is needed (Nightwatch alerts trigger on exceptions + auto-detected slow jobs/routes, NOT on log queries — see memory `reference_nightwatch_alerts`). Flag any "soft failure" that should be an exception.
- Heavy log payloads (full Shopify GraphQL response, full Stripe Event payload) inside a fan-out — log index OOM at scale.
- Slow-query / slow-job paths without a recognisable controller method or job name to attribute to in Nightwatch.

### (11) Schema correctness adjacent to the patterns above

- Tables that should have `UNIQUE` on (idempotency_key, scope) and don't.
- Status enums backed by VARCHAR without a `CHECK` constraint (the `64db1f2` pattern for `orders.rate_source`).
- Columns named `*_started_at` / `*_at` where the column is actually a deadline (or vice versa) — naming should match semantics, especially where category (3) anchor decoupling matters.
- Indexes on hot-read paths missing for the joins introduced by the new live queries the team has been moving toward.
- Duplicate indexes (the `de9bb8b` `site_visits` shape) — index hygiene under write amplification at the scale target.

## Per-finding requirements

For every finding:
- Cite the category number (1–11).
- Name the canonical replacement by short label: `lockForUpdate + UNIQUE`, `UniqueConstraintViolationException`, `*_started_at decoupling`, `JSONB dedup`, `daily reconcile job`, `verbatim vendor error capture`, `Policy + Form Request`, `bust :stale twin`, `jittered per-tenant invalidation`, `version-keyed cache`, `Bus::batch`, `Log-with-context`.
- Quantify expected impact at the scale target (1M orders/year, ~3K orders/day, ~10K daily payout jobs, ~40K daily notifications). A finding that's harmless at 30 brands but P1 at 200 brands is in scope and should say so.
- Quote verbatim evidence from the source files; do not invent line numbers.

## Out of scope — do NOT re-flag

- Findings already closed by `audit-2026-05-09-stripe-payout-lifecycle.md` (`#STRIPE-1` … `#STRIPE-4`) — these are deployed.
- The commerce schema (`commerce.orders` / `order_events` / `order_items` / `brand_affiliate_rollup` / `commission_movements`) — already audited, shipped 2026-05-06.
- `app/Services/Stripe/CommissionPayoutService` and `CommissionVoidService` — already audited.
- The deleted `commission_ledger_entries` model.
- Read-side weak caching / stampede / aggregate-tables-that-should-be-live-queries — covered by `scaling-antipatterns.md`. If a finding straddles both, emit under whichever is more specific.
- Findings about adding read replicas / sharding today — current load doesn't justify it; only flag code that **actively prevents** moving to replicas later.
- Findings about Laravel Cloud vs raw Kubernetes deployment — Laravel Cloud is the target.

## Suggested per-domain scope groups

Run the lens against one group at a time, not all at once. Each group is sized so the DeepSeek scan completes in a single pass and the adjudicator's findings stay re-runnable per domain.

### Group A — Shopify webhook + integration lifecycle (highest priority)
```
--scope app/Services/Shopify
--scope app/Jobs/Shopify
--scope app/Http/Controllers/Api/Webhooks
--scope app/Http/Controllers/Api/Shopify
--scope app/Http/Controllers/Api/Professional/ShopifyIntegration
```

### Group B — Notifications fan-out & dedup
```
--scope app/Services/Notifications
--scope app/Jobs/Notifications
--scope app/Notifications
--scope app/Http/Controllers/Api/Professional/Notifications
--scope app/Models/Core/Notifications
```

### Group C — Cache invalidation & write-path discipline
```
--scope app/Services/Cache
--scope app/Services/Analytics
--scope app/Jobs/Cache
--scope app/Observers
```

### Group D — Auth / policy gating on financial endpoints
```
--scope app/Policies
--scope app/Http/Controllers/Api/Professional/Stripe
--scope app/Http/Controllers/Api/Professional/Brand
--scope app/Http/Controllers/Api/Professional/Affiliate
--scope app/Http/Middleware/Auth
--scope app/Http/Requests
```

### Group E — Media, streaming, Hydrogen, Cloudflare (vendor-integration hygiene at the edges)
```
--scope app/Services/Media
--scope app/Services/Streaming
--scope app/Services/Hydrogen
--scope app/Services/Cloudflare
--scope app/Jobs/Cloudflare
--scope app/Jobs/Streaming
```

### Group F — Schema correctness adjacent to all of the above
```
--scope supabase/migrations
```
(Run last; most findings here will reference work flagged in earlier groups.)

## Exhaustiveness directive

Do NOT stop after the first finding in a category. Walk every file in the run's `--scope` and emit a finding for every distinct quotable instance. If three controllers each have inline role-scoping, that is three findings (`LIFE-1`, `LIFE-2`, `LIFE-3`), not one consolidated finding. If a single file has both a missing `lockForUpdate` and a swallowed exception, that is two findings. The adjudicator will dedupe and re-tier; **under-reporting is the failure mode to avoid**. Aim for breadth — keep going until every file in the group's scope has been read and every distinct quotable instance is recorded.
