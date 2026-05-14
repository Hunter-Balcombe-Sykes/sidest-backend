# Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention

Hunt **referential-integrity gaps**, **soft-delete inconsistencies**, **orphan-row risk**, and **PII / retention exposure** at the schema and model layer. This lens is **foundational** — the project status memory captures Josh's "speed ≠ cutting corners on systems that'll be built on" stance, and these are the bugs that compound silently for months before they bite.

This lens is a **sibling** to `security.md` (auth-surface PII exposure) and `lifecycle-correctness.md` (idempotency + race-safety). Where `security.md` looks at the **request boundary**, this lens looks at the **data-at-rest boundary**.

## Use the lens prefix `DATA` for findings

Number them `DATA-1`, `DATA-2`, … sequentially.

## Findings categories

### (1) Foreign-key constraints

- Tables with a `*_id` column that isn't backed by a `REFERENCES` clause — orphan-row risk on parent delete.
- FK constraints without an explicit `ON DELETE` rule — Postgres defaults to `NO ACTION` which is correct in most cases, but `CASCADE` / `SET NULL` / `RESTRICT` should be the deliberate choice, not the silent default.
- `ON DELETE CASCADE` on tables that should preserve audit history (e.g. `commission_movements`, `order_events`, `brand_status_history`) — financial / audit rows must not vanish when a parent is deleted.
- FK columns missing an index where the column is used in a `WHERE` or `JOIN` — Postgres does not auto-index FK columns.
- Multi-column FKs that don't have a matching composite index.

### (2) Soft-delete coherence

- Models that use the `SoftDeletes` trait without a corresponding `deleted_at` column in the migration — silent failure.
- Models without `SoftDeletes` whose parent uses `SoftDeletes` — child rows become orphans on soft-delete.
- Financial models with `SoftDeletes` (the `29b7eb1` test asserts none do — flag any survivor).
- `Builder` queries that need to include trashed records but use the default scope (`Model::withTrashed()` missing).
- Forced-delete paths that bypass FK cascade rules — orphan creation.
- Soft-deleted parents with non-soft-deleted children that are still reachable via the API.
- Soft-delete retention: the codebase has a 30-day retention default per CLAUDE.md — confirm a scheduled job actually purges trashed rows past retention, with audit logging.

### (3) Orphan-row risk

- Tables that store a polymorphic association (`*_type` + `*_id`) without a CHECK constraint or application-layer guarantee that the type+id combo points to a real row.
- Application-layer "soft FKs" (a UUID column that conceptually references another table, but Postgres doesn't know).
- Background jobs that delete a parent without considering child rows — flag any `Model::query()->delete()` on a parent table.
- Cleanup jobs missing for tables that should be GCed (orphaned `site_media` after design changes, orphaned `cart_events` after a cart expires, orphaned `commission_movements` for cancelled payouts).

### (4) Enum / CHECK constraint coverage

- Status / type columns backed by VARCHAR / TEXT without a CHECK constraint enumerating allowed values (the `64db1f2` pattern for `orders.rate_source`).
- Postgres ENUM types added without a corresponding application-side enum (drift risk — DB allows values the app can't handle).
- Application-side enums (`app/Enums/*`) without a matching DB CHECK — schema accepts garbage that the app rejects.
- Boolean-like columns stored as integer / varchar — flag any `is_*` or `has_*` column not typed as `BOOLEAN`.
- Numeric columns storing money as `INTEGER` (cents) vs `NUMERIC` — confirm the convention is consistent across financial tables.

### (5) Timestamp & timezone hygiene

- `TIMESTAMP` columns where `TIMESTAMPTZ` is needed (Postgres `TIMESTAMP` is timezone-naive — financial / audit rows must be tz-aware).
- `updated_at` columns that aren't auto-updated by a trigger or `BaseModel` — silent drift between row state and timestamp.
- `created_at` populated by application code instead of `DEFAULT now()` — race between insert time and clock skew across instances.
- Date-only columns (`DATE`) used for timestamps that should be instant.

### (6) JSONB schema drift

- JSONB columns used as a source of truth without an application-side schema or validator — silent drift between writes and reads.
- JSONB queries (`->jsonContains`, `->where('settings->foo', ...)`) without a GIN index — at-scale linear scan.
- JSONB columns growing unbounded (e.g. an append-only log inside a JSONB array) — row bloat + TOAST churn.
- JSONB fields that should be promoted to columns (queried often, joined to, or indexed) — flag any `WHERE settings->>'foo' = ...` that hits a hot path.

### (7) PII inventory & retention

- Columns storing email / phone / address / DOB / financial identifier without a row in the GDPR PII inventory (memory: `project_shopify_gdpr_webhooks_todo.md` notes the GDPR webhooks are complete and the PII inventory is preserved — verify this lens's findings are reconciled against that inventory).
- PII columns lacking a retention rule — long-tail accumulation.
- PII written to JSONB blobs without retention controls — invisible from a normal column audit.
- `customer_data_request` / `customer_redact` / `shop_redact` paths in `app/Http/Controllers/Api/Webhooks/` and `app/Jobs/Gdpr/`: confirm every PII column is touched by the redact path. The GDPR webhooks are implemented (memory `project_shopify_gdpr_webhooks_todo.md`) — flag any NEW PII columns added since 2026-04-21 that aren't yet wired into the redact jobs.
- Logging code paths that emit PII into Nightwatch / log aggregator (also covered by `security.md` category 10 — emit under whichever lens is more specific).

### (8) Backup / restore correctness boundaries

- Tables whose correct restore depends on FK ordering or trigger replay — flag any table where a partial restore would leave an inconsistent state.
- Trigger-maintained projections (`brand_affiliate_rollup` — the canonical example) — confirm a full rebuild path exists for disaster recovery.
- Append-only tables (`order_events`, `commission_movements`, `brand_status_history`) — confirm there is no UPDATE / DELETE path in code; restore must produce the same hashable state.

### (9) Composite-uniqueness coverage

- "Idempotency key" columns without a UNIQUE constraint backing them — the application's idempotency check is best-effort, not enforced.
- `(brand_id, code)` / `(professional_id, kind)` / `(shop_id, event_id)` natural keys without a UNIQUE constraint — duplicate rows on race.
- `UNIQUE` constraints on a single column where a composite would be correct (e.g. `UNIQUE(shop_id)` instead of `UNIQUE(shop_id, deleted_at)` on a table that allows re-installs).

## Per-finding requirements

For every finding:
- Cite the category number (1–9).
- Name the canonical fix: `ADD FOREIGN KEY ... REFERENCES ...`, `ADD CHECK ... IN (...)`, `CREATE INDEX CONCURRENTLY ...`, `ALTER COLUMN ... TYPE TIMESTAMPTZ`, `UNIQUE(col1, col2)`, `wire PII column into customer_redact job`, `promote JSONB key to column`, etc.
- Quote verbatim evidence from the migration / model file.

## Out of scope — do NOT re-flag

- The Stripe payout lifecycle audit's findings (closed).
- Commerce schema (`orders`, `order_events`, `order_items`, `brand_affiliate_rollup`, `commission_movements`) — shipped + audited.
- Booking / Fresha / Square schema (dropped).
- Findings about adding columns for product features that don't exist yet.

## Suggested per-domain scope groups

### Group A — Migrations (the source of truth)
```
--scope supabase/migrations
```

### Group B — Models + factories
```
--scope app/Models
--scope database/factories
```

### Group C — GDPR / retention paths
```
--scope app/Http/Controllers/Api/Webhooks
--scope app/Jobs/Gdpr
--scope app/Services
```

### Group D — Enums (DB / app drift)
```
--scope app/Enums
--scope supabase/migrations
```

## Exhaustiveness directive

Walk every migration and every model in scope. Emit a finding for every distinct quotable instance — missing FK, missing index on FK, missing CHECK, missing UNIQUE, missing soft-delete consideration. **The data layer is where silent corruption lives; under-reporting compounds.**
