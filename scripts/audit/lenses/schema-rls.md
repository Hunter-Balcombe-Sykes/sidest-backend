# Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety

Find database-side correctness gaps: missing constraints, RLS gaps, schema-qualification mistakes, migration patterns that break under load, index hygiene problems, trigger correctness, function-definition pitfalls. Partna's schema design is documented in `CLAUDE.md` (multi-schema with `search_path`) and `AI_CONTEXT.md`.

This lens is a **sibling** to `database-and-queue-scaling.md` (throughput / N+1 / queue) and `lifecycle-correctness.md` (idempotency / race-safety on the application side). Schema-side constraint gaps go here; application-side N+1 goes in scaling; application-side idempotency goes in lifecycle. The schema-side counterpart of an idempotency-key constraint (the `UNIQUE` index) lives in this lens. The adjudicator dedupes overlaps.

## Use the lens prefix `SCHEMA` for findings

Number them `SCHEMA-1`, `SCHEMA-2`, … sequentially across the whole audit.

## Findings categories

### (1) Row-level security (RLS)

- Tables in `commerce.*`, `brand.*`, `site.*`, `billing.*`, `notifications.*` containing tenant data without RLS enabled.
- RLS policies that use `current_user` / `session_user` instead of an app-set claim (e.g. `current_setting('app.actor_id')`) — RLS bypassed when the app connects as a shared role like `app_backend`.
- RLS policies that allow read where the row doesn't constrain by tenant — the policy exists but is permissive.
- Tables with RLS enabled but no `FORCE ROW LEVEL SECURITY` set — superuser / owner bypasses.
- Tables intended to be public (e.g. lookup tables) where RLS is enabled but no policy exists — legitimate reads silently fail.

### (2) `search_path` / multi-schema correctness

Partna's `search_path` includes `public`, `core`, `site`, `brand`, `commerce`, `notifications`, `analytics`, `billing`. Within Laravel models, table names are usually unqualified.

- Raw SQL queries (`DB::statement`, `whereRaw`, migration SQL) referencing a table without schema qualification when the same name could collide across schemas.
- Functions / triggers defined in one schema referencing tables in another without qualification — silent breakage if `search_path` changes.
- Migrations that change `search_path` for the session without restoring it — leaks into subsequent migration sessions.
- Models without an explicit `$table` property where the model is in a schema that isn't first on `search_path` — Laravel resolves to the wrong table.

### (3) Constraint coverage

- Status / enum columns backed by `VARCHAR` without a `CHECK` constraint — the `64db1f2` `orders.rate_source` pattern is the canonical replacement.
- Idempotency-key columns without a `UNIQUE` constraint backing them — INSERT retry on webhook re-delivery produces duplicates. The schema-side counterpart of `lifecycle-correctness.md` category (1).
- Columns the app code treats as `NOT NULL` (no null-handling on the read path) but the schema allows null — runtime crash class.
- Foreign keys without an explicit `ON DELETE` / `ON UPDATE` behavior — defaults to `NO ACTION`, produces production errors on delete cascades.
- Composite `UNIQUE` constraints missing where the app's read pattern implies "one row per (tenant, key)".
- Columns named `*_started_at` / `*_at` where the semantics are reversed (deadline vs. anchor) — see `lifecycle-correctness.md` (3); flag the schema-side naming mismatch here.

### (4) Index hygiene

- Hot-path queries (introduced by recent commits — see `git log`) without a composite index for the `WHERE` + `ORDER BY` + `LIMIT` shape.
- Partial indexes missing where a status filter dominates (`WHERE status = 'completed'` on a table where most rows aren't completed).
- GIN indexes missing on JSONB columns that are queried with `->>` or `@>` operators.
- Duplicate indexes (the `de9bb8b` `site_visits` shape) — index bloat under high-write volume.
- Indexes created without `CONCURRENTLY` against tables hot at the scale target.
- Indexes on columns that are never queried — write amplification with no read benefit.

### (5) Trigger correctness

- AFTER-trigger maintained rollups (the `brand_affiliate_rollup` pattern) without signed-delta logic — UPDATEs cause double-counting.
- Triggers that don't handle DELETE — soft-deleted rows still counted in aggregates.
- Triggers calling functions in a different schema without qualification.
- Triggers marked `BEFORE` that should be `AFTER` (or vice versa) — affects whether `NEW.id` is available, whether constraints are checked, etc.
- Triggers that perform unbounded work (cursor over all rows) — replace with row-bounded `NEW`/`OLD` logic.
- Triggers that fire on every row of a multi-row UPDATE / DELETE without statement-level batching where it would suffice.

### (6) Migration safety under load

- `ALTER TABLE ADD COLUMN ... NOT NULL` without a `DEFAULT` of a constant (Postgres 11+ avoids full-table rewrite only when the default is constant — verify each).
- `CREATE INDEX` without `CONCURRENTLY` on tables that will be hot at the scale target.
- New `CHECK` constraints added without `NOT VALID` + later `VALIDATE` — full-table scan blocks writes.
- Migrations that backfill data inline (loop in PHP migration code or raw SQL `UPDATE` over the whole table) instead of dispatching a separate backfill job — deploy blocked on long migration.
- Missing `SET lock_timeout` / `statement_timeout` on schema changes against hot tables — risk of unbounded blocking.
- `DROP COLUMN` migrations that don't first rename to `_deprecated` for a deploy cycle — reverting requires data restore.
- Reversible migrations whose `down()` re-creates indexes synchronously — `down()` deploy is as slow as `up()`.

### (7) Soft delete / retention pattern

- Tables with a `deleted_at` column but no `SoftDeletes` trait on the model — soft-delete scope is bypassed.
- Models with `SoftDeletes` but the underlying table missing the `deleted_at` column.
- 30-day retention cron (`SOFT_DELETE_RETENTION_DAYS`) not configured for new soft-deletable models.
- Foreign keys to soft-deletable parents without an explicit retention policy (do we cascade-soft-delete, or null out, or block?).

### (8) UUID + primary key consistency

- Tables with `BIGSERIAL` / `BIGINT` primary keys where the convention is UUID — flag the deviation.
- Tables with UUID PKs but no DB-side default (`gen_random_uuid()`) — relies on app-side generation, breaks raw INSERT and reconcile jobs.
- Tables with composite PKs where a single UUID would suffice — joins become more expensive without justification.

### (9) Function definitions

- `SECURITY DEFINER` functions that don't `SET search_path` explicitly — privilege escalation via mutable search_path is the canonical Postgres CVE shape.
- `SECURITY DEFINER` functions owned by a role with more privilege than the function needs.
- Functions labeled `VOLATILE` that are actually `STABLE` / `IMMUTABLE` — query planner can't cache results.
- Functions called from triggers that aren't `IMMUTABLE` — re-evaluated on every row.

### (10) JSONB design

- JSONB columns with documented shapes (in PHPDoc or migration comment) that don't match what the app code writes.
- JSONB columns queried with `->>` / `@>` without a GIN index.
- JSONB columns used as a substitute for a relation (one-to-many embedded as array) where a child table would scale better at the target load.
- Lack of versioning on JSONB shapes that have changed — old rows in old shape with no migration path.

### (11) Append-only vs mutable

(Sibling to `scaling-antipatterns.md` (6); focus here on schema-side discipline.)

- Tables intended as audit logs (`*_events`, `*_history`, `commission_movements`) with UPDATE paths — should be append-only. The DB should refuse UPDATE via trigger or revoked privilege, not rely on app discipline.
- Tables intended as projections that are append-only — should support UPDATE.
- Audit-log tables without an explicit dedup key (`shopify_event_id`, `stripe_event_id`) — webhook re-delivery duplicates.

## Per-finding requirements

For every finding:
- Cite the category number (1–11).
- Name the canonical replacement: `RLS policy with current_setting('app.actor_id')`, `CHECK constraint`, `UNIQUE constraint on (idempotency_key, scope)`, `CONCURRENTLY index`, `NOT VALID + VALIDATE`, `signed-delta trigger`, `SECURITY DEFINER with SET search_path`, `GIN on JSONB`, `gen_random_uuid() default`, etc.
- Quote verbatim SQL evidence from the migration files.
- Reference the canonical Partna pattern (`commerce.order_events`, `brand_affiliate_rollup`, `commission_movements`) where applicable.

## Out of scope — do NOT re-flag

- Already-audited and shipped schema (`commerce.orders` / `order_events` / `order_items` / `brand_affiliate_rollup` / `commission_movements`) — see `audit-2026-05-09-stripe-payout-lifecycle.md`.
- Findings about adding read replicas / sharding / partitioning today — only flag code that **actively prevents** these moves later.
- The deleted `commission_ledger_entries` model and its observer.
- `app_backend` role configuration (NOLOGIN fail-closed by design — see CLAUDE.md).

## Suggested per-domain scope groups

### Group A — schema migrations (broadest)
```
--scope supabase/migrations
```

### Group B — models + their tables (alignment between code and schema)
```
--scope app/Models
--scope supabase/migrations
```

### Group C — RLS-sensitive tenant tables
```
--scope supabase/migrations
--scope app/Models/Commerce
--scope app/Models/Retail
```

### Group D — triggers + functions
```
--scope supabase/migrations
```
(filter manually for files containing `CREATE TRIGGER` or `CREATE FUNCTION`)

## Exhaustiveness directive

Walk every migration file and every model in scope. Emit a finding for every distinct quotable instance. If three migrations each create an index without `CONCURRENTLY`, that is three findings (`SCHEMA-1`, `SCHEMA-2`, `SCHEMA-3`). The adjudicator dedupes. **Under-reporting is the failure mode to avoid.**
