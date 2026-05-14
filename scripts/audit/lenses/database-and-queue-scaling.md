# Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure

Find scaling failures of a different shape from the read-side caching / aggregate-rebuild patterns the team has already eliminated. This lens hunts the **Eloquent + Postgres + Horizon + Redis + vendor-API** failure modes that surface at the **200 brands × 50 affiliates × ~100 orders/affiliate/year** target — roughly **1M orders/year**, peak **~3K orders/day**, **~10K daily payout jobs**, **~40K daily notifications**, **~3K daily Shopify webhook deliveries**.

This lens is a **sibling** to `scaling-antipatterns.md` (read-side caching) and `lifecycle-correctness.md` (race / idempotency / vendor hygiene). Where the other two lenses focus on a hot read or an in-flight write, this one focuses on **throughput, capacity, and the resources that get exhausted before correctness fails**.

## Use the lens prefix `SCALE` for findings

Number them `SCALE-1`, `SCALE-2`, … sequentially across the whole audit.

## Findings categories

### (1) N+1 query patterns

- `foreach ($collection as $row) { $row->relation->... }` without an upstream `with('relation')`.
- Accessors on `BaseModel` that issue a query each time they're read (`getXAttribute` doing `->where(...)->first()`).
- Resource classes (`app/Http/Resources/*`) that load relations lazily inside `toArray` — every list endpoint hydrates N relations per row.
- `Collection::map` / `Collection::each` issuing per-item queries.
- Observers that read sibling rows on every save (e.g. parent counter recomputed via `->fresh()->children()->count()`).

### (2) Unbounded result sets / memory pressure

- `Model::all()` or `->get()` where the table will grow beyond ~10K rows at the scale target.
- Jobs that load a full collection into memory (`->get()` then `foreach`) instead of `->chunk(N)` / `->cursor()` / `LazyCollection`.
- `Bus::batch($jobs)` with `$jobs` materialised as a full array of job objects in memory.
- Response endpoints returning unpaginated lists.
- `Log::*` calls that pass a full GraphQL response, full Stripe Event payload, or full webhook body — log indices OOM and Nightwatch payload limits kick in.

### (3) Connection pool & transaction scoping

- `DB::transaction(...)` blocks that wrap external API calls (Stripe, Shopify, Cloudflare) — connection held while waiting on vendor I/O.
- Transactions that span multiple controller actions or persist across queued-job boundaries.
- Long-running jobs (`> 30s`) that hold an Eloquent connection open while idle (e.g. waiting on `sleep` or `usleep`).
- Connection leaks: code that opens a non-default connection (e.g. `redis_video`, Snowflake, S3) and doesn't return it to the pool.
- Per-request `DB::reconnect()` calls — flag any that shouldn't be there.

### (4) Queue / Horizon shape

- Jobs that should be on a domain queue (`notifications`, `webhooks`, `analytics`, `media`) but land on `default`.
- `config/horizon.php` supervisors with min/max process counts that don't match scale-target throughput (verify against current `horizon.php`, do NOT propose specific numbers without reading it).
- Jobs without `$tries` / `$backoff` / `$timeout` — retry storms or runaway execution on vendor outage.
- Jobs without a `failed()` handler on a path that has user-visible consequences (notifications, payouts, Hydrogen deploys).
- `Bus::chain` where order matters but the chain is dispatched on a queue that doesn't guarantee order under contention.
- Missing `WithoutOverlapping` / `Skip` middleware on jobs that should not run concurrently (e.g. brand-scoped re-deploys, per-tenant cache rebuilds).

### (5) Outbound vendor rate-limit budgets

- Shopify GraphQL calls without a rate-limit-cost annotation or a `Shopify-Storefront-API-Call-Limit` / `X-Shopify-Shop-Api-Call-Limit` header check — Shopify uses a points-based bucket; bursting drains the bucket and the next call is throttled.
- Stripe API calls in tight loops without explicit backoff (`Stripe-Should-Retry: true` handling).
- Cloudflare API calls (`CloudflareDnsService`) in synchronous bursts during DNS provisioning.
- Hydrogen / Oxygen deploy triggers without per-brand debounce.
- Mux / OpenAI / vendor calls inside `foreach` over tenant data — quantify the per-tenant call count at the scale target.

### (6) Scheduler stampede

- `routes/console.php` jobs scheduled at `everyMinute()` / `daily()` / `hourly()` with no `between()` window or `->after()` offset — multiple cron jobs firing at `:00` pile onto the same queue at the same instant.
- Per-tenant scheduled jobs that don't stagger across the tenant population — at 200 brands a "daily catalog sync for every brand at 03:00 UTC" lands 200 jobs in one second.
- Job classes that internally dispatch fan-out jobs without a per-tenant stagger.

### (7) Multi-tenant noisy-neighbour risk

- Shared queues where one brand's burst (e.g. a 1000-order Shopify import) can starve other brands' jobs.
- Lack of per-tenant rate limits on the API surface (a brand spamming `/me` is a DoS on Redis).
- Lack of per-tenant quotas on expensive paths (media transcode, Hydrogen redeploy, bulk catalog sync).
- Cache keys that collide across tenants (per-prefix metrics will reveal this but flag any code that doesn't include a tenant ID in the key namespace).

### (8) Migration safety under load

- `ALTER TABLE` that lacks `CONCURRENTLY` on index creation against tables that will be hot at the scale target.
- `NOT NULL` column additions with a default that requires a full-table rewrite (Postgres 11+ avoids rewrite for `DEFAULT` if the default is a constant — verify each).
- Backfills inside the migration itself instead of as a separate job — long migrations block deploy.
- Missing `SET lock_timeout` / `statement_timeout` on schema changes against hot tables.
- Reversible migrations that re-create indexes synchronously in `down()`.
- New CHECK constraints added without `NOT VALID` + later `VALIDATE` for hot tables.

### (9) Backpressure / webhook ingress

- Webhook controllers that do heavy work synchronously instead of acknowledging fast and dispatching a job.
- Queue depth alerting absent or undefined — at the scale target a 30-minute Shopify outage produces a 90K-event backlog when service resumes.
- No idle-worker scaling signal — if ingress 10×s, no signal tells Horizon to spin up more workers.
- Jobs that re-enqueue themselves on transient failure without exponential backoff — synthetic backpressure on the queue.

### (10) Index hygiene & query planner readiness

- New live queries introduced by recent commits (commerce live reads, embedded analytics) without verified composite indexes for the `WHERE` + `ORDER BY` + `LIMIT` shape.
- Duplicate indexes (the `de9bb8b` `site_visits` shape) — index bloat under high-write volume.
- Missing partial indexes where a status filter dominates the query (`WHERE status = 'completed'` on a table where most rows aren't).
- Unindexed JSONB queries — `WHERE settings->>'foo' = 'bar'` without a GIN index.
- Stale planner stats: tables with > 10% dead tuples likely need `ANALYZE` (only flag if the migration created the table; planner stats hygiene is otherwise out of scope).

### (11) Memory pressure in jobs / fan-out

- `Bus::batch()` invocations that build a >10K-job array in memory before dispatch.
- Notification fan-out that hydrates `NotificationReceipt` eagerly (one row per recipient at fan-out time) at recipient counts > 1K.
- Image / video pipeline jobs that load full file contents into PHP memory instead of streaming.
- Resource classes that hydrate full models when only an ID + display name is rendered.

## Per-finding requirements

For every finding:
- Cite the category number (1–11).
- Name the canonical replacement: `with(...)` eager load, `chunk()` / `cursor()`, `LazyCollection`, `domain-queue routing`, `$tries + $backoff + failed()`, `WithoutOverlapping`, `per-tenant rate limit`, `CONCURRENTLY index`, `acknowledge-fast + dispatch`, `composite index on (col1, col2)`, `streaming reads`, `Bus::batch(allowFailures: true)`, etc.
- Quantify against the scale target: 1M orders/year, ~3K orders/day, ~10K daily payout jobs, ~40K daily notifications, ~3K daily Shopify webhooks. A finding harmless at 30 brands but P1 at 200 brands is in scope.
- Quote verbatim evidence; do not invent.

## Out of scope — do NOT re-flag

- Read-side caching antipatterns (`scaling-antipatterns.md` owns these).
- Race / idempotency / anchor decoupling / reconcile loops (`lifecycle-correctness.md`).
- Commerce schema / `commerce.orders` / Stripe payout pipeline — already shipped.
- Booking, Fresha, Square — dropped from scope (see memory `project_booking_dropped.md`).
- Findings about adding read replicas or sharding today — only flag code that **actively prevents** moving to replicas later.

## Suggested per-domain scope groups

### Group A — Models & resources (N+1 + unbounded reads)
```
--scope app/Models
--scope app/Http/Resources
```

### Group B — Jobs & queue shape
```
--scope app/Jobs
--scope app/Console
--scope config/horizon.php
--scope config/queue.php
--scope routes/console.php
```

### Group C — Services with vendor I/O & transaction scoping
```
--scope app/Services/Shopify
--scope app/Services/Stripe
--scope app/Services/Cloudflare
--scope app/Services/Hydrogen
--scope app/Services/Media
--scope app/Services/Streaming
```

### Group D — Controllers (backpressure, list endpoints, accept-fast)
```
--scope app/Http/Controllers/Api/Webhooks
--scope app/Http/Controllers/Api/Professional
--scope app/Http/Controllers/Api/Staff
--scope app/Http/Controllers/Api/Internal
```

### Group E — Migrations under load
```
--scope supabase/migrations
```

## Exhaustiveness directive

Walk every file in the run's `--scope`. Emit a finding for every distinct quotable instance. If three jobs each lack `$tries`, that is three findings; if a single file has both an N+1 and an unbounded `->get()`, that is two findings. The adjudicator dedupes. **Under-reporting is the failure mode to avoid.**
