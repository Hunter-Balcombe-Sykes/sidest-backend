# ADR 0001 — Analytics & Data Layer Rebuild

**Status:** Accepted
**Date:** 2026-05-06
**Deciders:** Josh Hunter (founder/lead dev)
**Companion document:** `docs/analytics-rebuild-plan.md` (v3.1, audit-hardened)

## Context

The current analytics layer writes one `commission_ledger_entries` row per Shopify line item and rebuilds 6 aggregate tables (DELETE-then-INSERT) on every order webhook. The site analytics layer dispatches `RebuildSiteHourlyAggregatesJob` on every pageview/click. Both paths pay a perpetual write-amplification tax. The schema also diverges from every comparable affiliate platform (Refersion / Tapfiliate / AffiliateWP all use row-per-conversion-with-status).

Pre-beta is the cheapest window to fix this. Post-launch we'd need Stripe-style dual-write + Scientist shadow-read; pre-beta we can direct-cutover.

## Decision

Replace `commerce.commission_ledger_entries` (as the order-lifecycle source of truth) with:
- `commerce.orders` — mutable projection, one row per Shopify order
- `commerce.order_events` — append-only audit log, one row per webhook event
- `commerce.order_items` — normalized mirror of `line_items` JSONB (populated by trigger)
- `commerce.brand_affiliate_rollup` — incremental rollup table (trigger-maintained)

`commerce.commission_ledger_entries` is **renamed to `commerce.commission_movements`** and narrowed in scope to money-movement rows only (`payout`, `clawback`, `adjustment`). Accrual/reversal rows stop being written.

Site analytics keeps the raw event tables (`site_visits`, `link_clicks`, `cart_events`) and drops the rebuild-aggregate machinery entirely. Live queries on raw events with a hardened cache layer replace the aggregate-table reads.

## Schema decisions

- All `_cents` columns use `bigint` (overflow-safe to ~$92 quadrillion vs `integer`'s ~$21M cap).
- Order identity: `(shopify_shop_domain, shopify_order_id)` unique index. No separate `idempotency_key` column.
- Webhook idempotency: `commerce.order_events.shopify_event_id` unique partial index, populated from `X-Shopify-Event-Id` (Shopify's documented idempotency primitive).
- Out-of-order delivery defense: `INSERT ... ON CONFLICT DO UPDATE WHERE EXCLUDED.shopify_updated_at > orders.shopify_updated_at` (last-write-wins on Shopify's clock).
- Stub rows: `status='stub'` for refunds/edits/cancellations arriving before the parent `orders/paid`. Stubs are excluded from live queries and the rollup trigger.
- Rollup `day` key is **UTC**; brand-local timezone display happens in the read controller.
- Per-line commission is **pre-computed by the webhook handler in PHP** and serialized into `line_items` JSONB before upsert. The `order_items` trigger reads from JSONB. Rationale: Postgres triggers can't access PHP business logic (product metafields, brand defaults), so commission must be resolved before storage.

## RLS posture

Every new table enables RLS with two policies, mirroring `commerce.commission_ledger_entries`:
- `<table>_party_select` — brand and affiliate can SELECT their own rows
- `<table>_staff_all` — `core.sidest_staff` has full ALL access

Webhook jobs and backfill commands run via service-role connection (RLS bypassed). Read paths run via authenticated user JWT (RLS enforced).

## GDPR strategy

PII lives in:
- `commerce.orders.shopify_data` (billing/shipping address, customer name/email/phone, customer-authored `note`, `note_attributes[*].value`, `line_items[*].properties[*].value`)
- `commerce.order_events.metadata` (refund.note, adjustment.note, denormalized customer fields)
- `commerce.orders.customer_id` (FK)

`RedactCustomerJob` and `RedactShopJob` extended to:
1. NULL `customer_id` on matching orders
2. Call `jsonb_strip_pii(shopify_data, ARRAY[...paths...])` with the enumerated path list
3. Call `jsonb_strip_pii(metadata, ARRAY[...paths...])` on every event row referencing the order

`jsonb_strip_pii` is a new `IMMUTABLE` Postgres function with wildcard support for `[*]` array paths.

## Partition strategy

Not partitioning at launch. Revisit when:
- `commerce.orders` exceeds 10M rows (pg_partman by year), OR
- `commerce.order_events` exceeds 50M rows (pg_partman by month), OR
- `analytics.site_visits` exceeds 100M rows (pg_partman by month)

At 30 brands × ~50 affiliates × ~100 orders/affiliate/year = 150k orders/year. Even aggressive 1.5M orders/year gives 5 years before the orders threshold. Site visits will hit the threshold sooner — **monitor `pg_class.reltuples` quarterly**.

BRIN index on `commerce.orders(occurred_at)` is included from day one — cheap (~kilobytes), and effective for append-mostly insertion patterns. Backfill must insert in `occurred_at` order so initial physical layout correlates.

## Cache strategy

- Default TTL: 60s (industry standard for "real-time" tier per Sigma/GoodData)
- Single-flight via existing `CacheLockService::rememberLocked` (Redis-only; never file driver)
- TTL jitter: ±20%
- Stale-while-revalidate: return last-good on stale; refresh in background
- Version tokens via `analyticsSummaryVersion` (existing pattern)
- Push-invalidation on every commerce.orders write and every site-event ingest

## Migration approach (pre-beta)

Direct cutover in one PR. Five phases:
- **Phase 0** — this ADR (1 day)
- **Phase 1** — schema migration + RLS + triggers + models + tests (1–2 days)
- **Phase 2** — backfill + verification (2–3 days)
- **Phase 3** — webhook + read-path cutover in single PR (3–5 days)
- **Phase 4** — drop deprecated aggregate tables, dead code, test data (1 day, after Phase 3 +24h)

## Rollback approach

Pre-beta = cheap rollback:
1. `git revert` the cutover PR
2. Re-run forward migration to recreate dropped jobs/services
3. Optionally drop new commerce.* tables and let Phase 1 migration recreate them

Post-launch this changes — see "Future Rebuilds: When to Use Scientist" section in the plan doc.

## Out of scope for this rebuild

- Multi-currency commission flows (single-currency-per-shop stays)
- Multi-shop-per-brand per-shop dimension in rollup (combined under brand_professional_id)
- Commission recalculation on `orders/edited` (frozen at original-order rate)
- Partitioning (revisit at thresholds above)
- TimescaleDB / continuous aggregates (not available on Supabase)

## Consequences

**Positive:**
- ~10× reduction in webhook ingest write amplification (1 row + 1 trigger vs N rows + 2 jobs × 6 writes)
- Schema matches industry-standard affiliate platform patterns
- Live-query analytics tractable through ~10M orders without partitioning
- Audit log via `order_events` preserves the legitimate auditability the ledger provided
- GDPR redaction explicit and enumerated

**Negative:**
- Significant code churn in webhook jobs and analytics controllers (~20 files)
- Triggers add a small (sub-ms) tax to every order insert
- BRIN effectiveness depends on `occurred_at`-ordered backfill — degrades silently if not enforced
- `commerce.commission_movements` table holds historical accrual rows until Phase 4 cleanup; brief naming mismatch during Phase 2-3

**Risks acknowledged:**
- Reconciler may expose webhook gaps post-launch (mitigation: Hookdeck as fallback if real)
- Trigger throughput at 100+ orders/sec untested (benchmark at end of Phase 3)
- Rollup divergence from clawback if separate trigger fails (Phase 1 test must cover)

## References

- Plan document: `docs/analytics-rebuild-plan.md` (v3.1)
- Audit: `audit-2026-05-05-analytics-rebuild-plan-v3.md`
- DeepSeek raw findings: `audit-drafts-1777977050.md`
