# Analytics & Data Layer Rebuild — Plan v3.1 (Pre-Beta Direct Rebuild, audit-hardened)

**Status:** Draft for review. Pre-beta — no customer data at risk.
**Author:** Josh + Claude, 2026-05-05
**Supersedes:** v2 (which proposed a Scientist + dual-write multi-week migration). v1 (which proposed a from-scratch orders table with lateral-join breakdowns and 15s TTL caching).
**v3 → v3.1 changelog:** 8 findings (3 P1 + 5 P2) from the dual-worker audit (`audit-2026-05-05-analytics-rebuild-plan-v3.md`) plus 4 self-review items folded in. See "What Changed From v1 / v2 / v3" table at the bottom.
**Scope:** Replace the per-line-item commission ledger + rebuild-aggregate model with an `orders + status + event_log` model fed by hardened Shopify webhooks, served by live queries with a stampede-safe cache layer, augmented by trigger-maintained rollup tables. Same scope for site analytics: kill rebuild-aggregate jobs, query raw events directly with caching.

---

## TL;DR

The current system pays a perpetual write-amplification tax. Every Shopify order webhook writes one ledger row per line item AND fires two `RebuildCommerce*AggregatesJob` jobs that DELETE-then-INSERT into 6 aggregate tables. Every site pageview/click fires `RebuildSiteHourlyAggregatesJob` that does the same against site analytics tables. At zero customers this is free; once orders are flowing it's the dominant DB workload, and the schema diverges from every comparable affiliate platform.

The rebuild adopts the industry-standard pattern: **`commerce.orders` (mutable projection) + `commerce.order_events` (immutable audit log) + `commerce.order_items` (normalized JSONB mirror) + `commerce.brand_affiliate_rollup` (trigger-maintained)**. The existing `commerce.commission_ledger_entries` is kept but narrowed in scope to money-movement rows only (payouts, clawbacks, manual adjustments) — preserving its legitimate double-entry value while removing it from the order lifecycle.

For site analytics: keep raw `site_visits` / `link_clicks` / `cart_events` tables, drop the rebuild-aggregate machinery, query raw events with the same hardened cache layer.

**Migration:** direct cutover in one PR. Pre-beta with no customers means we can drop and recreate `commerce.*` at will. Verify the new schema models real-shape data via a one-shot backfill from the existing ledger, then swap controllers and webhook jobs in a single PR. ~1–2 weeks of work, not 4–6.

---

## Why a Direct Rebuild (and when this approach would NOT apply)

This plan deliberately skips the Scientist + shadow-read + dual-write machinery that v2 proposed. That machinery is the right answer when you have:
- Paying customers seeing dashboards in real time
- Production data that can't be regenerated
- Schema changes where the new path's correctness is genuinely uncertain
- Multi-week observability windows budgeted

**None of those apply pre-beta.** The data in the ledger today is test/development data, the schema for the new path is well-specified, and a clean cutover with verification tests is sufficient.

**For future rebuilds (e.g., partitioning at 10M orders, switching commission calculation logic, or any post-launch refactor of `commerce.*`)**, see the "Future Rebuilds: When to Use Scientist" section at the bottom — that machinery becomes load-bearing once real money is flowing.

This is documented explicitly so future-you (or whoever inherits this) doesn't read the abbreviated migration here and assume the pattern generalizes.

---

## What the Current System Actually Does

Verified by reading the codebase, not assumed:

### Commerce write path (Shopify webhook → DB)
- `ShopifyOrderWebhookController` validates HMAC, deduplicates on `X-Shopify-Webhook-Id` (24h Redis TTL), dispatches `ProcessShopifyOrderWebhookJob`.
- `ProcessShopifyOrderWebhookJob` writes **one row per line item** to `commerce.commission_ledger_entries`. Idempotency key: `shopify_order_{orderId}_line_{lineItemId}`.
- After ledger writes, dispatches `RebuildCommerceDailyAggregatesJob` + `RebuildCommerceHourlyAggregatesJob`.
- `ProcessShopifyOrderUpdatedWebhookJob` handles refunds: full refund updates accruals to `status='reversed'`; partial refund inserts new `entry_type='reversal'` rows. Same rebuild dispatch.

### Site analytics write path (frontend pixel → DB)
- `PublicSite\AnalyticsController::pageview()` writes `site_visits` + dispatches `RebuildSiteHourlyAggregatesJob`.
- `PublicSite\AnalyticsController::click()` writes `link_clicks` + dispatches `RebuildSiteHourlyAggregatesJob`.
- `PublicSite\AnalyticsController::cartEvent()` writes `cart_events` (no rebuild dispatch — it doesn't feed aggregates).

### Read path (Dashboard → DB)
- `BrandCommerceAnalyticsController` reads from `brand_metrics_daily/hourly`, `brand_affiliate_daily`, `brand_commission_daily`. Cached with `CacheLockService::rememberLocked` at 5-minute TTL with version-token invalidation.
- `AffiliateCommerceAnalyticsController` reads same aggregates. Same caching.
- `ProfessionalAnalyticsController` reads raw `site_visits`, `link_clicks`, `cart_events` plus aggregate tables. 5-min TTL today / 24-hr TTL historical.

### Why this is the wrong shape
- Write amplification: every order webhook = ~20 row-writes + 2 dispatched jobs that each do ~6 aggregate-table writes. Every pageview = 1 raw insert + 1 rebuild job that does ~2 site-metrics writes.
- Schema divergence: every comparable affiliate platform (Refersion / Tapfiliate / AffiliateWP) uses row-per-conversion-with-status, not append-only ledger.
- Aggregate tables are derived state masquerading as primary data. Anytime the rebuild logic has a bug, the aggregates lie.

---

## Target Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│             SHOPIFY WEBHOOKS (idempotent, ordered)              │
│      orders/paid · orders/edited · orders/cancelled             │
│              · refunds/create · orders/updated                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼  HMAC + X-Shopify-Event-Id dedup
                   ┌──────────────────────────┐
                   │  ProcessOrderWebhookJob  │
                   │  (per-topic handlers)    │
                   └──────────────────────────┘
                              │
                              ▼  INSERT ... ON CONFLICT
                              ▼  WHERE EXCLUDED.shopify_updated_at >
                              ▼          orders.shopify_updated_at
   ┌────────────────────────────┐    ┌─────────────────────────────┐
   │  commerce.orders           │    │  commerce.order_events      │
   │  (mutable projection)      │◄───┤  (append-only audit log,    │
   │  - status, totals (bigint) │    │   one row per webhook)      │
   │  - shopify_updated_at      │    │  - shopify_event_id (UNIQUE)│
   │  - line_items jsonb        │    │  - shopify_triggered_at     │
   │  - RLS enabled             │    │  - source, metadata jsonb   │
   └────────────────────────────┘    └─────────────────────────────┘
            │                                      │
            ▼  TRIGGER on insert/update            │
   ┌────────────────────────────┐                  │
   │  commerce.order_items      │                  │
   │  (normalized mirror)       │                  │
   └────────────────────────────┘                  │
            │                                      │
            ▼ TRIGGER incrementally maintains      ▼
   ┌─────────────────────────────────┐    ┌─────────────────────┐
   │ commerce.brand_affiliate_rollup │    │ commerce.commission │
   │ (per brand × affiliate × day,   │    │ _movements          │
   │  UTC, signed deltas)            │    │ (renamed from       │
   └─────────────────────────────────┘    │  ledger_entries;    │
                                          │  money movements    │
                                          │  only: payouts,     │
                                          │  clawbacks, manual) │
                                          └─────────────────────┘

                              │
                              ▼ READ PATH (commerce + site)
                   ┌────────────────────────────┐
                   │  Analytics Controllers     │
                   │  - live PG queries         │
                   │  - hardened Redis cache    │
                   │  - 60s default TTL         │
                   │  - single-flight locks     │
                   │  - TTL jitter ±20%         │
                   │  - version-token bumping   │
                   └────────────────────────────┘
```

### Why this shape
1. **`orders` (mutable) + `order_events` (immutable) is the documented industry-standard hybrid.** Refersion / Tapfiliate / AffiliateWP all use row-per-conversion-with-status. Append-only ledgers are best-practice for *money movement*, not order lifecycle.
2. **Keep the renamed `commission_movements` table for money movements only.** Payouts, clawbacks, manual adjustments — where double-entry invariants are load-bearing.
3. **Trigger-maintained rollup tables for the heaviest reports**, not full aggregate-table machinery. The Citus rollup pattern is unambiguous: at scale, full-refresh views lose to incremental rollups.
4. **Live queries + cache for everything else.** Postgres handles 50–100M rows comfortably with right indexes; we won't reach that for years.
5. **Separate `order_items` table** populated by trigger from `line_items` JSONB. JSONB lacks per-key planner statistics; normalized columns win for aggregate analytics.
6. **Site analytics: keep raw event tables, kill rebuild dispatch.** Same cache layer, same query model.

---

## Schema

All `_cents` columns use `bigint` (not `integer`) to avoid overflow at $21M. RLS is enabled on every new table with policies matching existing `commerce.*` table patterns (party-select for brand/affiliate, staff-write for service operations).

### `commerce.orders`
One row per Shopify order. Updated in place via `INSERT ... ON CONFLICT DO UPDATE WHERE EXCLUDED.shopify_updated_at > orders.shopify_updated_at` — last-write-wins on Shopify's clock, defending against out-of-order webhook delivery.

```sql
CREATE TABLE commerce.orders (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Shopify identity
    shopify_order_id text NOT NULL,
    shopify_shop_domain text NOT NULL,
    shopify_updated_at timestamptz NOT NULL,         -- last-write-wins guard

    -- Tenancy
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id),
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id),
    customer_id uuid REFERENCES core.customers(id) ON DELETE SET NULL,

    -- State
    status text NOT NULL DEFAULT 'pending'
        CHECK (status IN ('stub','pending','approved','partially_refunded','refunded',
                          'cancelled','voided')),

    -- Money (bigint for overflow safety)
    gross_cents bigint NOT NULL,                     -- pre-tax, post-discount line subtotal sum
    discount_cents bigint NOT NULL DEFAULT 0,
    refund_cents bigint NOT NULL DEFAULT 0,
    net_cents bigint NOT NULL,                       -- gross - refund (discount already in gross)
    commission_cents bigint NOT NULL,                -- frozen at order-paid time (Decision #3)
    commission_rate numeric(7,4) NOT NULL,
    rate_source text NOT NULL,                       -- 'product_metafield' | 'brand_default' | 'platform_default' | 'manual'
    currency_code char(3) NOT NULL DEFAULT 'AUD',

    -- Raw + reconciliation
    line_items jsonb NOT NULL DEFAULT '[]',
    shopify_data jsonb NOT NULL DEFAULT '{}',        -- redact_pii() called by GDPR jobs
    reconciled_at timestamptz,

    -- Stripe linkage
    stripe_payment_intent_id text,
    stripe_transfer_id text,
    payout_id uuid REFERENCES commerce.commission_payouts(id) ON DELETE SET NULL,

    occurred_at timestamptz NOT NULL,                -- Shopify created_at, in UTC
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

-- Identity uniqueness (replaces v2's redundant idempotency_key column)
CREATE UNIQUE INDEX uq_orders_shop_order
    ON commerce.orders(shopify_shop_domain, shopify_order_id);

-- Read-path indexes
CREATE INDEX idx_orders_brand_status_occurred
    ON commerce.orders(brand_professional_id, status, occurred_at DESC);
CREATE INDEX idx_orders_affiliate_status_occurred
    ON commerce.orders(affiliate_professional_id, status, occurred_at DESC);
CREATE INDEX idx_orders_brand_affiliate_occurred
    ON commerce.orders(brand_professional_id, affiliate_professional_id, occurred_at DESC);

-- Append-only timeseries scan (BRIN works only because we insert in occurred_at order)
CREATE INDEX idx_orders_occurred_brin
    ON commerce.orders USING BRIN(occurred_at)
    WITH (pages_per_range = 32);

-- Reconciliation worker
CREATE INDEX idx_orders_unreconciled
    ON commerce.orders(brand_professional_id, shopify_updated_at)
    WHERE reconciled_at IS NULL;

-- Payout eligibility — 'approved' = fully captured + (no refund OR refund < gross)
CREATE INDEX idx_orders_payable
    ON commerce.orders(affiliate_professional_id, currency_code)
    WHERE status = 'approved' AND payout_id IS NULL;

-- RLS policies (mirrors commerce.commission_ledger_entries pattern)
ALTER TABLE commerce.orders ENABLE ROW LEVEL SECURITY;

CREATE POLICY orders_party_select ON commerce.orders FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR
        affiliate_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
    );

CREATE POLICY orders_staff_all ON commerce.orders TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));
```

**BRIN caveat:** Postgres docs are explicit — BRIN only works when physical row order correlates with the indexed value. Backfill must insert in `occurred_at` order. Do NOT `CLUSTER` the table on a different column.

### `commerce.order_events`
Append-only audit log. Every state change writes a row. The unique partial index on `shopify_event_id` is the canonical idempotency primitive (per Shopify's [Ignore duplicate webhooks](https://shopify.dev/docs/apps/build/webhooks/ignore-duplicates) doc).

```sql
CREATE TABLE commerce.order_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id uuid NOT NULL REFERENCES commerce.orders(id) ON DELETE CASCADE,

    event_type text NOT NULL CHECK (event_type IN (
        'created', 'paid', 'edited', 'fulfilled',
        'partially_refunded', 'refunded',
        'cancelled', 'voided',
        'commission_approved', 'commission_paid', 'commission_clawed_back',
        'manual_adjustment'
    )),

    amount_delta_cents bigint,                       -- signed delta to commission/refund
    metadata jsonb NOT NULL DEFAULT '{}',            -- redact_pii() called by GDPR jobs

    -- Source tracking
    source text NOT NULL CHECK (source IN ('webhook','reconciler','manual','stripe','system')),
    shopify_event_id text,                           -- X-Shopify-Event-Id (when source='webhook')
    shopify_triggered_at timestamptz NOT NULL,       -- X-Shopify-Triggered-At for cross-topic ordering

    occurred_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_order_events_order ON commerce.order_events(order_id, shopify_triggered_at);
CREATE UNIQUE INDEX uq_order_events_shopify_event
    ON commerce.order_events(shopify_event_id) WHERE shopify_event_id IS NOT NULL;

ALTER TABLE commerce.order_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY order_events_party_select ON commerce.order_events FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM commerce.orders o
        WHERE o.id = commerce.order_events.order_id
        AND (
            o.brand_professional_id = (SELECT id FROM core.professionals
                WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
            OR
            o.affiliate_professional_id = (SELECT id FROM core.professionals
                WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        )
    ));

CREATE POLICY order_events_staff_all ON commerce.order_events TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));
```

### `commerce.order_items`
Normalized mirror of `line_items` JSONB, populated by `AFTER INSERT OR UPDATE OF line_items` trigger that diffs JSONB and reconciles rows. Used for top-products / GMV-by-SKU queries.

**Commission source contract (audit fix PLANV3-2):** Per-line commission cannot be computed from raw Shopify line_items alone — it depends on Partna product metafields, brand defaults, and platform defaults that live in PHP. Therefore the **webhook handler pre-computes per-line `commission_cents` and `commission_rate` and serializes them into each `line_items` JSONB element** before the upsert. The trigger reads these out, treating the values as authoritative.

Required JSONB element shape (each line item):
```json
{
  "shopify_line_item_id": "...",
  "shopify_product_id": "...",
  "shopify_variant_id": "...",
  "sku": "...",
  "title": "...",
  "quantity": 2,
  "unit_price_cents": 4500,
  "discount_cents": 500,
  "line_total_cents": 8500,
  "commission_cents": 850,
  "commission_rate": 10.0
}
```

The trigger function signature: `INSERT INTO commerce.order_items (...) SELECT (jsonb_array_elements(NEW.line_items)->>...) ... ON CONFLICT (order_id, shopify_line_item_id) DO UPDATE ...`. For stub orders (`status='stub'`), the trigger skips because `line_items='[]'`.

```sql
CREATE TABLE commerce.order_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id uuid NOT NULL REFERENCES commerce.orders(id) ON DELETE CASCADE,

    shopify_line_item_id text NOT NULL,
    shopify_product_id text NOT NULL,
    shopify_variant_id text,
    sku text,
    title text NOT NULL,

    quantity integer NOT NULL,
    unit_price_cents bigint NOT NULL,
    discount_cents bigint NOT NULL DEFAULT 0,
    line_total_cents bigint NOT NULL,
    commission_cents bigint NOT NULL,
    commission_rate numeric(7,4) NOT NULL,

    -- Denormalized for analytics filtering (no extra join)
    brand_professional_id uuid NOT NULL,
    affiliate_professional_id uuid NOT NULL,
    occurred_at timestamptz NOT NULL,
    currency_code char(3) NOT NULL,

    UNIQUE (order_id, shopify_line_item_id)
);

CREATE INDEX idx_order_items_brand_product_occurred
    ON commerce.order_items(brand_professional_id, shopify_product_id, occurred_at DESC);
CREATE INDEX idx_order_items_affiliate_product_occurred
    ON commerce.order_items(affiliate_professional_id, shopify_product_id, occurred_at DESC);

ALTER TABLE commerce.order_items ENABLE ROW LEVEL SECURITY;

CREATE POLICY order_items_party_select ON commerce.order_items FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR
        affiliate_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
    );

CREATE POLICY order_items_staff_all ON commerce.order_items TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));
```

### `commerce.brand_affiliate_rollup` (trigger-maintained)
The only aggregate table we keep. Maintained by `AFTER INSERT OR UPDATE` trigger on `commerce.orders` using signed-delta `INSERT ... ON CONFLICT DO UPDATE`. **Day key is UTC** — brand-local timezone display happens in the read controller, not the storage.

```sql
CREATE TABLE commerce.brand_affiliate_rollup (
    day date NOT NULL,                               -- UTC date
    brand_professional_id uuid NOT NULL,
    affiliate_professional_id uuid NOT NULL,
    currency_code char(3) NOT NULL,

    orders_count integer NOT NULL DEFAULT 0,
    gross_cents bigint NOT NULL DEFAULT 0,
    refund_cents bigint NOT NULL DEFAULT 0,
    net_cents bigint NOT NULL DEFAULT 0,
    commission_cents bigint NOT NULL DEFAULT 0,
    reversed_commission_cents bigint NOT NULL DEFAULT 0,

    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, affiliate_professional_id, currency_code)
);

ALTER TABLE commerce.brand_affiliate_rollup ENABLE ROW LEVEL SECURITY;

CREATE POLICY rollup_party_select ON commerce.brand_affiliate_rollup FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR
        affiliate_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
    );

CREATE POLICY rollup_staff_all ON commerce.brand_affiliate_rollup TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));
```

#### Rollup trigger spec (audit fix PLANV3-3)

The `AFTER INSERT OR UPDATE` trigger on `commerce.orders` applies signed deltas to the rollup. Pseudocode contract — every case must be implemented and tested:

```
function rollup_apply_delta() RETURNS TRIGGER:
  -- Skip stub rows entirely; they don't represent real orders yet
  if NEW.status = 'stub' OR (TG_OP = 'UPDATE' AND OLD.status = 'stub' AND NEW.status = 'stub'):
    return NEW

  day := DATE(NEW.occurred_at AT TIME ZONE 'UTC')
  key := (day, NEW.brand_professional_id, NEW.affiliate_professional_id, NEW.currency_code)

  if TG_OP = 'INSERT' OR (TG_OP = 'UPDATE' AND OLD.status = 'stub'):
    -- New order, OR stub being promoted to a real order
    delta := {
      orders_count: +1,
      gross_cents: +NEW.gross_cents,
      refund_cents: +NEW.refund_cents,
      net_cents: +NEW.net_cents,
      commission_cents: +NEW.commission_cents,
      reversed_commission_cents: +0
    }

  elif TG_OP = 'UPDATE':
    -- Status moves to terminal cancelled/voided: reverse all previously counted values
    if NEW.status IN ('cancelled','voided') AND OLD.status NOT IN ('cancelled','voided'):
      delta := {
        orders_count: -1,
        gross_cents: -OLD.gross_cents,
        refund_cents: -OLD.refund_cents,
        net_cents: -OLD.net_cents,
        commission_cents: -OLD.commission_cents,
        reversed_commission_cents: +OLD.commission_cents  -- track the full reversal
      }

    else:
      -- Generic field-level deltas (refund_cents goes up, status changes to refunded, etc.)
      delta := {
        orders_count: 0,
        gross_cents: NEW.gross_cents - OLD.gross_cents,
        refund_cents: NEW.refund_cents - OLD.refund_cents,
        net_cents: NEW.net_cents - OLD.net_cents,
        commission_cents: NEW.commission_cents - OLD.commission_cents,
        -- Proportional commission reversal for refunds
        reversed_commission_cents:
          ROUND(((NEW.refund_cents - OLD.refund_cents)::numeric
                 / NULLIF(NEW.gross_cents, 0))
                * NEW.commission_cents)
      }

  -- Apply via UPSERT
  INSERT INTO commerce.brand_affiliate_rollup (key, ...delta..., updated_at)
  VALUES (...)
  ON CONFLICT (day, brand_professional_id, affiliate_professional_id, currency_code)
  DO UPDATE SET
    orders_count = brand_affiliate_rollup.orders_count + EXCLUDED.orders_count,
    gross_cents = brand_affiliate_rollup.gross_cents + EXCLUDED.gross_cents,
    refund_cents = brand_affiliate_rollup.refund_cents + EXCLUDED.refund_cents,
    net_cents = brand_affiliate_rollup.net_cents + EXCLUDED.net_cents,
    commission_cents = brand_affiliate_rollup.commission_cents + EXCLUDED.commission_cents,
    reversed_commission_cents = brand_affiliate_rollup.reversed_commission_cents
                              + EXCLUDED.reversed_commission_cents,
    updated_at = now()

  return NEW
```

Test cases that must pass in Phase 1:
- INSERT happy-path order → rollup gains +1 count, +commission
- INSERT then partial refund (UPDATE refund_cents) → reversed_commission_cents grows proportionally
- INSERT then cancellation → orders_count reverts to 0, all amounts subtract back
- Stub INSERT → no rollup change
- Stub UPDATE to approved → rollup gains +1 count as if new
- Idempotent UPDATE (NEW = OLD) → all deltas zero, no rollup change

#### Clawback → rollup sync (audit fix PLANV3-4)

Post-payout clawbacks are written to `commerce.commission_movements` (entry_type='clawback'). They never modify `commerce.orders` rows (commission is frozen on orders, by design). The order-level rollup trigger therefore never fires for clawbacks, leaving `reversed_commission_cents` stale.

**Add an `AFTER INSERT` trigger on `commerce.commission_movements`** that, for `entry_type='clawback'`, applies a signed delta directly to the rollup:

```
function rollup_apply_clawback() RETURNS TRIGGER:
  if NEW.entry_type != 'clawback':
    return NEW

  -- Look up the affected order's day/brand/affiliate from the movement's order_id
  SELECT date(occurred_at AT TIME ZONE 'UTC'), brand_professional_id,
         affiliate_professional_id, currency_code
  INTO _day, _brand, _aff, _currency
  FROM commerce.orders WHERE id = NEW.order_id

  INSERT INTO commerce.brand_affiliate_rollup
    (day, brand_professional_id, affiliate_professional_id, currency_code,
     reversed_commission_cents, updated_at)
  VALUES (_day, _brand, _aff, _currency, NEW.amount_cents, now())
  ON CONFLICT (day, brand_professional_id, affiliate_professional_id, currency_code)
  DO UPDATE SET
    reversed_commission_cents = brand_affiliate_rollup.reversed_commission_cents
                              + EXCLUDED.reversed_commission_cents,
    updated_at = now()

  return NEW
```

Test case: create order → payout → clawback → assert rollup `commission_cents - reversed_commission_cents` equals expected net.

**Multi-shop policy:** A single brand can connect multiple Shopify shops. The rollup combines all of them under one `brand_professional_id` (no per-shop dimension in the rollup PK). If per-shop breakdown is ever needed, it comes from a live query against `commerce.orders` keyed on `shopify_shop_domain`.

### `commerce.commission_movements` (renamed from `commission_ledger_entries`, scope reduced)
The existing ledger stays but its scope narrows: **only money-movement rows**. Renamed to make the new scope obvious to readers.
- `entry_type='payout'` — written when a payout is settled
- `entry_type='clawback'` — written when commission is reversed after payout (post-payout refunds)
- `entry_type='adjustment'` — manual support corrections

`entry_type='accrual'` and `entry_type='reversal'` rows stop being written; the order's `status` field replaces them. Existing accrual/reversal rows are dropped at end of migration (pre-beta = test data only).

This preserves the legitimate double-entry invariants for money movement while removing them from the order lifecycle.

### `commerce.commission_payout_items` migration
Add `order_id` column (Decision #4):
```sql
ALTER TABLE commerce.commission_payout_items ADD COLUMN order_id uuid REFERENCES commerce.orders(id);
-- Backfill from FK chain
UPDATE commerce.commission_payout_items cpi SET order_id = o.id
FROM commerce.commission_movements cm
JOIN commerce.orders o ON o.shopify_order_id = cm.shopify_order_id
WHERE cpi.commission_movement_id = cm.id;
-- After backfill verifies clean:
ALTER TABLE commerce.commission_payout_items ALTER COLUMN order_id SET NOT NULL;
ALTER TABLE commerce.commission_payout_items DROP COLUMN commission_movement_id;
```

---

## Webhook Ingest

### Topic strategy
Subscribe to specific topics, not the noisy `orders/updated` catch-all:
- `orders/paid` — initial accrual creation
- `orders/edited` — line-item changes via the Order Editing API (snapshot-only update; commission frozen — Decision #3)
- `orders/cancelled` — cancellation before/after fulfillment
- `refunds/create` — partial or full refund
- `orders/updated` — kept as safety net only; deduplicated against more specific topics
- (Already have: `customers/data_request`, `customers/redact`, `shop/redact` for GDPR)

### Idempotency (two-tier)
1. **HTTP layer (cheap pre-filter)** — current Redis dedup on `X-Shopify-Webhook-Id` (24h TTL). Keep as-is.
2. **DB layer (durable)** — unique constraint on `commerce.order_events.shopify_event_id` populated from `X-Shopify-Event-Id`. Per [Shopify's official guidance](https://shopify.dev/docs/apps/build/webhooks/ignore-duplicates), this is the documented primitive.

### Out-of-order handling
Shopify [does not guarantee ordering](https://shopify.dev/docs/apps/build/webhooks): `products/update` can arrive before `products/create`. Real-world bug — [shopify-app-js issue #603](https://github.com/Shopify/shopify-app-js/issues/603) saw `orders/updated` arrive before `orders/create` within a second.

**Three distinct races to handle:**

**Race 1 — `orders/updated` before `orders/paid`** (the canonical order arrives first):
```sql
INSERT INTO commerce.orders (...)
VALUES (...)
ON CONFLICT (shopify_shop_domain, shopify_order_id)
DO UPDATE SET
    status = EXCLUDED.status,
    gross_cents = EXCLUDED.gross_cents,
    -- ... all mutable fields ...
    shopify_updated_at = EXCLUDED.shopify_updated_at,
    updated_at = now()
WHERE EXCLUDED.shopify_updated_at > commerce.orders.shopify_updated_at;
```

**Race 2 — `refunds/create` before the parent `orders/paid` is recorded.** The refund handler must:
1. Look up the order. If absent: insert a stub `commerce.orders` row (`status='stub'`, totals from refund context, `shopify_updated_at = refund.created_at - 1ms`) — this guarantees the next `orders/paid` will overwrite via the WHERE-clause guard.
2. Record the refund event in `order_events`.
3. Apply the refund delta to `orders.refund_cents`.

**Race 3 — `orders/edited` or `orders/cancelled` before `orders/paid`.** Same stub pattern as Race 2: insert a stub row with `status='stub'` (cancelled stubs flip to `status='cancelled'`). Stub-row column defaults for the NOT NULL commission columns:

| Column | Stub value | Notes |
|---|---|---|
| `commission_cents` | `0` | Will be overwritten by `orders/paid` LWW guard |
| `commission_rate` | `0` | Same |
| `rate_source` | `'pending'` | New sentinel value; `rate_source` has no CHECK constraint, so the schema accepts it |
| `gross_cents` / `net_cents` | extracted from event payload if present, else `0` | |

**Stub status semantics:** `'stub'` is a distinct value from `'pending'` so dashboards and analytics queries can exclude it explicitly. CHECK constraint becomes:
```sql
CHECK (status IN ('stub','pending','approved','partially_refunded','refunded','cancelled','voided'))
```

The rollup trigger MUST skip stub rows (`status='stub'`) so they don't pollute `orders_count` during the brief gap window. When `orders/paid` arrives and flips status away from `stub`, the rollup trigger fires on the UPDATE and applies the full delta as if the order were inserted fresh.

When `orders/paid` eventually arrives with a higher `shopify_updated_at`, the stub becomes a real order with refund_cents already applied.

### Reconciliation
Webhook-only is not sufficient — Shopify's own docs say so. Reconciler runs hourly for the first 60 days post-launch, then daily (Decision #5). Schedule is config-driven:

```php
// routes/console.php
$schedule->command('shopify:reconcile-orders')
    ->cron(config('sidest.reconciler.schedule', '0 3 * * *'));
```

Set `SIDEST_RECONCILER_SCHEDULE='0 * * * *'` for launch + 60 days, then revert. Nightwatch alert: any non-zero discrepancy after the first 60 days pages immediately.

```php
// app/Console/Commands/ReconcileShopifyOrders.php
foreach ($integrations as $integration) {
    $since = $integration->reconciled_through ?? now()->subDays(7);
    $shopOrders = $shopify->orders()->where('updated_at_min', $since)->cursor();

    foreach ($shopOrders->lazyById(250) as $shopOrder) {
        $local = Order::where('shopify_shop_domain', $integration->domain)
            ->where('shopify_order_id', $shopOrder['id'])
            ->first();

        if (! $local || $local->shopify_updated_at < $shopOrder['updated_at']) {
            ProcessShopifyOrderWebhookJob::dispatchSync($integration->id, $shopOrder, source: 'reconciler');
        }
    }

    $integration->update(['reconciled_through' => now()]);
}
```

### Tax handling
Commission base is **pre-tax**: `(unit_price × quantity) - line_discount`. This matches existing `ProcessShopifyOrderWebhookJob` logic. `commerce.orders.gross_cents` excludes tax. Tax is not stored separately because it's not used in any commission or analytics calculation; if needed later, parse from `shopify_data` JSONB.

### GDPR redaction (audit-derived)
The new tables hold customer PII via:
- `commerce.orders.shopify_data` (billing/shipping addresses, customer name + email + phone, plus customer-authored free text)
- `commerce.order_events.metadata` (event-specific PII, e.g. refund customer notes, manual adjustment reasons)

Update `RedactCustomerJob` and `RedactShopJob` to:
1. NULL `customer_id` on matching `commerce.orders`.
2. `shopify_data = jsonb_strip_pii(shopify_data, ARRAY[...])` with the explicit path list below.
3. `metadata = jsonb_strip_pii(metadata, ARRAY[...])` on every `commerce.order_events` row referencing that order.

**Enumerated PII paths in `commerce.orders.shopify_data`** (audit fix PLANV3-5 — added free-text customer-authored fields):
- `customer.email`, `customer.first_name`, `customer.last_name`, `customer.phone`
- `billing_address.*`, `shipping_address.*`
- `note` (customer order/gift note)
- `note_attributes[*].value` (custom checkout form fields)
- `line_items[*].properties[*].value` (product customization fields, e.g. engraving text)

**Enumerated PII paths in `commerce.order_events.metadata`** (one of the v2 audit's biggest gaps — left empty entirely):
- `refund.note` (refund reason staff typed)
- `adjustment.note` (manual support adjustment reason)
- `customer.email`, `customer.name`, `customer.phone` (when denormalized for display)

Test case: insert a synthetic order + event with PII in BOTH standard fields AND free-text fields; run RedactCustomerJob; assert no identifiable data remains in either JSONB.

**`jsonb_strip_pii` SQL function skeleton:**
```sql
CREATE OR REPLACE FUNCTION public.jsonb_strip_pii(input jsonb, paths text[])
RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
  result jsonb := input;
  path_str text;
BEGIN
  IF input IS NULL THEN RETURN NULL; END IF;

  FOREACH path_str IN ARRAY paths LOOP
    -- Wildcard support: 'note_attributes[*].value' matches each array element's .value
    IF position('[*]' IN path_str) > 0 THEN
      result := jsonb_strip_pii_wildcard(result, path_str);
    ELSE
      result := jsonb_set(result, string_to_array(path_str, '.'), '"REDACTED"'::jsonb, false);
    END IF;
  END LOOP;

  RETURN result;
END;
$$;
```
The wildcard helper iterates JSONB arrays and recursively redacts the path under each element. Implementation finalized during Phase 1; for now the contract is "given a JSONB and a list of dotted paths (with optional `[*]` array wildcards), return JSONB with those paths set to `"REDACTED"`".

---

## Read Path

### Live queries, no aggregate-table joins
Each metric is a single indexed query. Examples:

```sql
-- Brand totals (current month)
SELECT
    COUNT(*)                           AS orders_count,
    COALESCE(SUM(gross_cents), 0)      AS gross_cents,
    COALESCE(SUM(net_cents), 0)        AS net_cents,
    COALESCE(SUM(commission_cents), 0) AS commission_cents
FROM commerce.orders
WHERE brand_professional_id = $1
  AND status NOT IN ('stub','cancelled','voided','refunded')   -- 'stub' excluded so out-of-order
                                                               -- placeholder rows don't pollute totals
  AND occurred_at BETWEEN $2 AND $3;

-- Per-affiliate breakdown — reads pre-rolled-up table, NOT live aggregation
SELECT
    affiliate_professional_id,
    SUM(orders_count)         AS orders_count,
    SUM(gross_cents - refund_cents) AS net_cents,
    SUM(commission_cents - reversed_commission_cents) AS commission_cents
FROM commerce.brand_affiliate_rollup
WHERE brand_professional_id = $1
  AND day BETWEEN $2 AND $3
  AND currency_code = $4
GROUP BY affiliate_professional_id
ORDER BY commission_cents DESC
LIMIT 100;

-- Site analytics (visits by day) — reads raw event tables, no rebuild jobs
SELECT
    DATE(occurred_at AT TIME ZONE 'UTC') AS day,
    COUNT(*)                              AS visits,
    COUNT(DISTINCT visitor_id)            AS unique_visitors
FROM analytics.site_visits
WHERE professional_id = $1
  AND occurred_at BETWEEN $2 AND $3
GROUP BY day
ORDER BY day;
```

### Cache layer (hardened)
Default TTL: **60s** (industry standard for "real-time" tier per Sigma/GoodData). All hot keys must have:

1. **Single-flight lock** via existing `CacheLockService::rememberLocked` — Redis-only, never file driver in production.
2. **TTL jitter** — random ±20% on every set.
3. **Stale-while-revalidate** — on stale cache, return last-good and dispatch background refresh job.
4. **Version tokens** — already in codebase (`analyticsSummaryVersion`); keep.
5. **Separate Redis connections** for locks vs cache data (so `Cache::flush()` doesn't nuke locks mid-flight).

### Push invalidation
On every `commerce.orders` write, bump version tokens for `brand:{brandId}:analytics` and `affiliate:{affiliateId}:analytics`. The rollup-trigger handles per-day invalidation automatically.

For site analytics, every `site_visits`/`link_clicks`/`cart_events` insert bumps the per-affiliate token.

---

## Migration: Direct Cutover

Pre-beta means we can drop and recreate `commerce.*` at will. No customers, no real money in flight. **One PR per phase, ~1–2 weeks total.**

### Phase 0 — ADR (1 day)
- Confirm `pg_cron` is available and version (≥1.6.4).
- Confirm `pg_partman` available (for future partitioning revisit at 10M orders).
- Write a one-page ADR documenting schema decisions, RLS posture, GDPR redaction strategy, partition strategy, and rollback approach (which is "git revert + re-run migrations" pre-beta).
- No code changes.

### Phase 1 — Create New Schema (1–2 days)
- Migration creates `commerce.orders`, `commerce.order_events`, `commerce.order_items`, `commerce.brand_affiliate_rollup`.
- All RLS policies inline.
- All triggers: `order_items` JSONB diff, `brand_affiliate_rollup` signed-delta.
- All indexes including BRIN.
- Eloquent models + relationships + Form Requests + Resource classes.
- `ALTER TABLE commerce.commission_payout_items ADD COLUMN order_id uuid REFERENCES commerce.orders(id);` (nullable for now).
- `ALTER TABLE commerce.commission_payout_items RENAME COLUMN commission_ledger_entry_id TO commission_movement_id;` (FK column renamed alongside the table rename — audit fix self-(b)).
- `jsonb_strip_pii` SQL function.
- Tests written (table-creation tests, RLS smoke tests, trigger tests with raw inserts).
- Nothing wired to production write or read paths yet.

### Phase 2 — Backfill + Verify (2–3 days)
Goal: prove the new schema models real-shape data correctly. Pre-beta data is test data, so this is a *correctness verification step*, not a data-preservation step.

- **Pre-flight check** (Decision #4): run the multi-line-orders verification query before backfill begins.
- **Disable rollup trigger AND `order_items` trigger** during backfill (`ALTER TABLE commerce.orders DISABLE TRIGGER trg_rollup, DISABLE TRIGGER trg_order_items_diff;`) — row-by-row triggers on bulk insert are slow, and we'll do a one-shot recompute at the end.
- Single command: `php artisan commerce:backfill-orders`.
- **Insertion order MUST preserve `occurred_at`** (audit fix PLANV3-6) so the BRIN index on `occurred_at` works. Use `commission_movements`-grouped-by-`shopify_order_id` ordered by `MIN(occurred_at)`:
  ```php
  CommissionMovement::query()
      ->whereIn('entry_type', ['accrual','reversal'])
      ->select('shopify_order_id', DB::raw('MIN(occurred_at) AS first_seen'))
      ->groupBy('shopify_order_id')
      ->orderBy('first_seen')           // critical for BRIN
      ->chunk(1000, function ($orderGroups) { ... });
  ```
  Resume safety: persist the high-water-mark `first_seen` cursor to `storage/app/backfill-cursor.txt`. On re-run, start from that timestamp.
- **Status mapping algorithm** (explicit, not hand-wavy):
  ```
  for each order grouped by shopify_order_id:
    accruals = entries where entry_type='accrual'
    reversals = entries where entry_type='reversal'
    sum_accrual = sum(accruals.amount_cents)
    sum_reversal = sum(reversals.amount_cents)  -- negative numbers

    if sum_reversal == 0:
      status = 'approved'
    elif abs(sum_reversal) >= sum_accrual:
      status = 'refunded'
    elif abs(sum_reversal) > 0:
      status = 'partially_refunded'

    refund_cents = abs(sum_reversal)
    commission_cents = sum_accrual  -- frozen at original
  ```
- Reconstruct `line_items` JSONB from `calculation_metadata` per line entry — including the per-line `commission_cents` and `commission_rate` fields per the order_items contract.
- **Insert orders via `INSERT ... ON CONFLICT (shopify_shop_domain, shopify_order_id) DO NOTHING`** (audit fix self-(a): the v3 schema dropped the `idempotency_key` column; the unique key is now the shop+order_id composite).
- Backfill `order_events` from movements: each `accrual` → `created` + `paid`; each `reversal` → `partially_refunded` or `refunded`; each `payout`-linked entry → `commission_paid`.
- Backfill `commission_payout_items.order_id` from FK chain.
- **Re-enable triggers and run one-shot rollup recompute:**
  ```sql
  ALTER TABLE commerce.orders ENABLE TRIGGER trg_rollup, ENABLE TRIGGER trg_order_items_diff;

  -- One-shot rollup recompute (cheaper than per-row trigger on backfill)
  TRUNCATE commerce.brand_affiliate_rollup;
  INSERT INTO commerce.brand_affiliate_rollup
    (day, brand_professional_id, affiliate_professional_id, currency_code,
     orders_count, gross_cents, refund_cents, net_cents,
     commission_cents, reversed_commission_cents)
    SELECT date(occurred_at AT TIME ZONE 'UTC'),
           brand_professional_id, affiliate_professional_id, currency_code,
           COUNT(*), SUM(gross_cents), SUM(refund_cents), SUM(net_cents),
           SUM(commission_cents),
           SUM(ROUND((refund_cents::numeric / NULLIF(gross_cents, 0)) * commission_cents))
    FROM commerce.orders
    WHERE status != 'stub'
    GROUP BY 1, 2, 3, 4;

  -- One-shot order_items rebuild (since trigger was disabled during backfill)
  INSERT INTO commerce.order_items (...)
    SELECT ... FROM commerce.orders, jsonb_array_elements(line_items) AS li
    ON CONFLICT (order_id, shopify_line_item_id) DO NOTHING;

  -- Verify BRIN correlation post-backfill (audit fix PLANV3-6)
  SELECT correlation FROM pg_stats
   WHERE schemaname='commerce' AND tablename='orders' AND attname='occurred_at';
  -- If correlation < 0.9, run: REINDEX INDEX CONCURRENTLY idx_orders_occurred_brin;
  ```
- **Verification command:** `php artisan commerce:backfill-verify` — diffs row counts and per-(brand, affiliate, day) sums between movements and new tables. Also samples 50 random orders and field-by-field compares.
- Acceptance: zero discrepancies. Re-run if any. Pre-beta = no rollback drama; if backfill is wrong, drop and redo.

### Phase 3 — Cutover in One PR (3–5 days)
Single PR that:
- **Webhook write path:**
  - `ProcessShopifyOrderWebhookJob` rewritten to write `commerce.orders` + `order_events` + (trigger writes `order_items` + rollup). No more accrual/reversal writes.
  - `ProcessShopifyOrderUpdatedWebhookJob` rewritten for `orders/edited` (snapshot only, no commission change), `orders/cancelled`, `refunds/create` (with stub-creation race handling).
  - Switch idempotency from `X-Shopify-Webhook-Id` to `X-Shopify-Event-Id` (DB unique constraint on `order_events.shopify_event_id` is the durable guarantee).
  - Drop dispatch of `RebuildCommerceDailyAggregatesJob` and `RebuildCommerceHourlyAggregatesJob`.
- **Site analytics write path:**
  - `PublicSite\AnalyticsController::pageview()` and `click()` — drop `RebuildSiteHourlyAggregatesJob` dispatch. Just write the raw event + bump version token.
- **Read path:**
  - `BrandCommerceAnalyticsController` — switch to live queries on `commerce.orders` + `brand_affiliate_rollup`.
  - `AffiliateCommerceAnalyticsController` — same.
  - `ProfessionalAnalyticsController` — switch to live queries on raw `site_visits` / `link_clicks` / `cart_events` for visits/clicks/cart-events; commerce stats from `commerce.orders`.
  - Keep `CacheLockService::rememberLocked` pattern; bump TTL to 60s; add jitter and SWR.
- **Reconciler:** add `ReconcileShopifyOrders` command, schedule via config (Decision #5).
- **GDPR:** update `RedactCustomerJob` and `RedactShopJob` for new tables.
- **Delete in same PR:**
  - `RebuildCommerceDailyAggregatesJob`, `RebuildCommerceHourlyAggregatesJob`, `RebuildSiteHourlyAggregatesJob`
  - `CommerceAnalyticsAggregateService`, `SiteAnalyticsAggregateService`
  - `CompactHourlyAnalytics`, `CompactSiteHourlyAnalytics` commands
  - `BrandCommerceAnalyticsController` (replaced by new logic)
- **Run `composer test`** — full suite green.
- **Run `php artisan pint`** — style clean.
- Manual smoke test against dev environment with synthetic Shopify webhook payloads.

### Phase 4 — Drop Old Aggregate Tables (1 day, after Phase 3 in production for 24h)
Migration (wrap in single transaction; **order matters** — audit fix PLANV3-7):
- **Pre-flight verification:**
  ```sql
  SELECT count(*) FROM commerce.commission_payout_items cpi
  JOIN commerce.commission_movements cm ON cm.id = cpi.commission_movement_id
  WHERE cm.entry_type IN ('accrual','reversal');
  -- Must be 0 — payout_items should reference only payout/clawback/adjustment movements.
  -- If non-zero: investigate and fix payout_items.order_id backfill before proceeding.
  ```
- `ALTER TABLE commerce.commission_payout_items ALTER COLUMN order_id SET NOT NULL;`
- `ALTER TABLE commerce.commission_payout_items DROP COLUMN commission_movement_id;` **— FK drops first**
- `DELETE FROM commerce.commission_movements WHERE entry_type IN ('accrual','reversal');` (test data, no longer needed) **— now safe**
- `DROP TABLE analytics.brand_metrics_daily, analytics.brand_metrics_hourly, analytics.brand_affiliate_daily, analytics.brand_commission_daily, analytics.professional_metrics_daily, analytics.professional_metrics_hourly, analytics.site_metrics_daily, analytics.site_metrics_hourly;`
- **Delete dead PHP code** (audit fix self-(d) — explicit list, not just "drop dispatch"):
  - `app/Jobs/Analytics/RebuildCommerceDailyAggregatesJob.php`
  - `app/Jobs/Analytics/RebuildCommerceHourlyAggregatesJob.php`
  - `app/Jobs/Analytics/RebuildSiteHourlyAggregatesJob.php`
  - `app/Services/Analytics/CommerceAnalyticsAggregateService.php`
  - `app/Services/Analytics/SiteAnalyticsAggregateService.php`
  - `app/Console/Commands/CompactHourlyAnalytics.php`
  - `app/Console/Commands/CompactSiteHourlyAnalytics.php`
  - `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php` (replaced)
  - Eloquent models for the dropped aggregate tables under `app/Models/Analytics/`
- Verify `rg "RebuildCommerce|RebuildSite|CommerceAnalyticsAggregateService" app/` returns nothing.
- Update CLAUDE.md and AI_CONTEXT.md with new model, naming, and the `orders/edited` commission-freeze policy (Decision #3).

**Total: ~1.5 weeks of focused work.**

### Rollback approach (pre-beta)
If something goes wrong in production after Phase 3:
1. `git revert` the cutover PR.
2. Re-run forward migration to recreate the dropped jobs/services (they're in version control).
3. Optionally drop the new commerce tables and let Phase 1 migration recreate them on next deploy.

There's no customer data to preserve. Rollback is fast and clean. Once you have customers, this changes — see "Future Rebuilds" below.

---

## Verification Plan

### Phase 1 (schema)
- `php artisan test --parallel` — green. Specific tests:
  - All 4 new tables exist with correct columns/types/indexes.
  - RLS policies block cross-tenant reads (test by setting auth.uid() to a different professional and asserting 0 rows visible).
  - `order_items` trigger correctly populates rows from `line_items` JSONB on order insert.
  - `brand_affiliate_rollup` trigger correctly applies signed deltas (insert order → +1 count; refund event → +refund_cents; reversal → +reversed_commission_cents).
  - `INSERT ... ON CONFLICT DO UPDATE WHERE` correctly drops older `shopify_updated_at`.
  - `commerce.order_events.shopify_event_id` unique constraint blocks duplicate events.

### Phase 2 (backfill)
- Row count match: `COUNT(*) commerce.orders` = `COUNT(DISTINCT shopify_order_id) commerce.commission_movements`.
- Sum match: per (brand, affiliate, UTC day, currency), SUM(commission_cents) in orders matches SUM(net commission) in movements.
- Sample audit: 50 random orders compared field-by-field old vs new (especially line_items reconstruction).
- Rollup recompute SUM matches commerce.orders SUM.

### Phase 3 (cutover)
- Manual smoke tests with synthetic Shopify webhook payloads:
  - Happy path: orders/paid → check orders + order_events + order_items + rollup all written.
  - Refund path: orders/paid → refunds/create → check status='partially_refunded', refund_cents updated, rollup updated.
  - Out-of-order: orders/updated arriving first → orders/paid arriving second → final state correct.
  - Out-of-order race 2: refunds/create before orders/paid → stub created → orders/paid overwrites stub correctly.
  - Idempotency: same X-Shopify-Event-Id twice → second one no-ops.
  - `orders/edited`: snapshot updated, commission unchanged.
- Read path:
  - `EXPLAIN ANALYZE` on per-affiliate breakdown query shows index scan, not seq scan.
  - Cache hit returns < 10ms.
  - Cache miss returns < 500ms for 30-day brand range.

### Phase 4 (cleanup)
- `composer test` green (no broken references).
- All deleted controllers/jobs/services have no remaining call sites (`rg "RebuildCommerce" app/` returns nothing).

---

## Risks & Open Questions

| Risk | Mitigation |
|------|-----------|
| Backfill produces wrong totals | Verification command must show zero discrepancies before Phase 3 starts. Pre-beta = re-run if wrong, no rollback drama. |
| BRIN index degrades after backfill | Backfill orders movements by `MIN(occurred_at)` before chunking (audit fix PLANV3-6). Verify `pg_stats.correlation` ≥ 0.9 after backfill; if not, run `REINDEX INDEX CONCURRENTLY idx_orders_occurred_brin`. |
| Trigger on `commerce.orders` slows webhook ingest | Trigger does signed-delta UPSERT into rollup — single indexed write per order. Should add <1ms. Benchmark before cutover. |
| Cache stampede on hot brand dashboard | Single-flight lock + jitter + SWR. Already partially in place. |
| Webhook reconciler exposes large gaps post-launch | Means webhooks are being lost. Investigate Shopify delivery logs; consider Hookdeck as fallback queue. |
| Refunds-before-paid race | Stub-creation logic in `ProcessShopifyRefundCreatedJob` — handle explicitly with synthetic test. |
| Frozen-commission UX confusion | If a brand edits an order downward, gross changes but commission_cents stays — needs clear UI label ("commission frozen at original order value"). Document in AI_CONTEXT.md. |
| `orders/edited` increases order value | Symmetric tradeoff of freezing: affiliate doesn't earn extra. Acceptable per Decision #3. Revisit if upsell-after-checkout becomes a real revenue pattern. |
| Partition strategy needed sooner than expected | Plan revisit at 10M orders. Switch to `pg_partman` monthly partitions; existing indexes stay valid. |

### Decisions (resolved 2026-05-05)

1. **Rename `commission_ledger_entries` → `commission_movements`** in Phase 1 (consolidated into the cutover PR for v3 since there's no extended dual-write window). Eloquent model `CommissionLedgerEntry` → `CommissionMovement` in same PR.

2. **Single-currency-per-shop stays.** No schema change. `currency_code` on `commerce.orders` is a copy of the order's Shopify currency, validated against the integration's `shop_currency`. Multi-currency expansion is a separate future project.

3. **Freeze commission at original-order rate.** `orders/edited` updates the order snapshot but does NOT modify `commission_cents`, `commission_rate`, or insert reversal rows. Reductions in commission only happen via `refunds/create`. Symmetric tradeoff (additions don't earn more) accepted.

4. **Add `order_id` to `commission_payout_items`.** Phase 1 adds nullable column; Phase 2 backfills; Phase 4 makes NOT NULL and drops old `commission_movement_id`.

5. **Reconciler runs hourly for first 60 days post-launch, then daily.** Schedule driven by `SIDEST_RECONCILER_SCHEDULE` config so it can flip without a deploy.

---

## What Changed From v1 / v2 / v3

| # | v1 said | v2 said | v3 says | Reason |
|---|---------|---------|---------|--------|
| 1 | Replace ledger entirely | Keep ledger for money movements; new orders model for order lifecycle | Same as v2, plus rename to `commission_movements` in cutover PR | Industry pattern (Refersion/Tapfiliate); pre-beta means no risk of mid-migration rename |
| 2 | "No caching today" | False premise; keep `CacheLockService::rememberLocked` | Same as v2 | Code review |
| 3 | 15s default TTL | 60s default TTL | Same as v2 | Sigma/GoodData industry analysis |
| 4 | Plain `Cache::remember` | `Cache::lock` + jitter + SWR + version tokens | Same as v2 | Cache-stampede literature |
| 5 | `LEFT JOIN LATERAL` for breakdown | Trigger-maintained `brand_affiliate_rollup` | Same as v2 | Citus rollup vs matview |
| 6 | All-JSONB line_items | JSONB raw + normalized `order_items` mirror | Same as v2 | Heap "When to avoid JSONB" |
| 7 | Dual-write only | Dual-write + Scientist shadow-read | **Direct cutover, no Scientist** | Pre-beta = no customers; Scientist is overkill for test data |
| 8 | No reconciliation | Daily reconciler | Hourly first 60 days, then daily | Shopify docs; launch-window observability |
| 9 | `X-Shopify-Webhook-Id` for idempotency | `X-Shopify-Event-Id` (DB unique) | Same as v2 | Shopify "Ignore duplicate webhooks" doc |
| 10 | Single `orders/updated` topic | Specific topics + `updated_at` LWW | Same as v2, plus refunds-before-paid race handling | Audit finding |
| 11 | No partition strategy | Revisit at 10M orders | Same as v2 | Future-proofing |
| 12 | B-tree on `occurred_at` only | B-tree composites + BRIN | Same as v2 | Postgres BRIN docs |
| 13 | "Drop aggregates immediately" | Keep `brand_affiliate_rollup` only | Same as v2 | Citus rollup |
| 14 | No matview mention | Explicitly rejected | Same as v2 | Concurrent-refresh constraints |
| 15 | "Backfill" without specifics | `lazyById(1000)` resume-safe | Same as v2 with explicit status-mapping algorithm + trigger-disable during backfill | Audit finding |
| 16 | Site analytics not addressed | Site analytics not addressed | **Site analytics rebuild jobs killed in same PR** | v2 oversight; same write-amplification anti-pattern |
| 17 | `integer` cents | `integer` cents | **`bigint` cents everywhere** | Audit finding: integer overflow at $21M |
| 18 | No RLS spec | No RLS spec | **RLS policies inline in every table DDL** | Audit finding: Postgres default = no RLS = PostgREST bypass |
| 19 | No GDPR plan | No GDPR plan | **`jsonb_strip_pii` + redact-job updates explicit** | Audit finding: `shopify_data` and `metadata` JSONB hold PII |
| 20 | Tax handling implicit | Tax handling implicit | **Pre-tax commission base documented** | Audit finding |
| 21 | `idempotency_key` column on orders | `idempotency_key` column on orders | **Dropped — redundant with `uq_orders_shop_order`** | Audit finding |
| 22 | `shopify_triggered_at` on orders | `shopify_triggered_at` on orders | **Removed from orders; lives only on `order_events`** | Audit finding: per-event field |
| 23 | FK ON DELETE behavior unspecified | FK ON DELETE behavior unspecified | **`customer_id`, `payout_id` set to ON DELETE SET NULL** | Audit finding |
| 24 | Multi-shop policy implicit | Multi-shop policy implicit | **Documented: rollup combines all shops per brand** | Audit finding |
| 25 | Rollup timezone unspecified | Rollup timezone unspecified | **Pinned to UTC; brand-local display in controller** | Audit finding |

### v3 → v3.1 (audit fixes folded in)

| # | v3 said | v3.1 says | Audit finding |
|---|---------|-----------|---------------|
| 26 | `orders/edited` / `orders/cancelled` first-seen behavior implicit | **Explicit stub-row contract**: `status='stub'`, `commission_cents=0`, `commission_rate=0`, `rate_source='pending'`. Stub status added to CHECK constraint. | PLANV3-1 |
| 27 | `order_items.commission_cents` NOT NULL with no source | **Webhook handler pre-computes per-line commission and serializes into `line_items` JSONB** before upsert. Trigger reads from JSONB. | PLANV3-2 |
| 28 | Rollup trigger described in shape but no body | **Full pseudocode contract** covering INSERT, UPDATE-cancel, UPDATE-refund, stub-promotion, idempotent-update cases | PLANV3-3 |
| 29 | Clawbacks invisible to rollup | **`AFTER INSERT` trigger on `commission_movements`** for `entry_type='clawback'` writes signed delta to rollup `reversed_commission_cents` | PLANV3-4 |
| 30 | `order_events.metadata` GDPR paths empty | **Enumerated**: `refund.note`, `adjustment.note`, `customer.email/name/phone`. `shopify_data` paths extended to include `note`, `note_attributes[*].value`, `line_items[*].properties[*].value` | PLANV3-5 |
| 31 | Backfill `lazyById(1000)` (UUID order) contradicts BRIN requirement | **Backfill ordered by `MIN(occurred_at)`**; post-backfill BRIN correlation check (≥0.9 or REINDEX) | PLANV3-6 |
| 32 | Phase 4 DELETE before DROP COLUMN (FK violation) | **Reordered**: DROP COLUMN first, then DELETE. Wrapped in transaction. Pre-flight verification query added. | PLANV3-7 |
| 33 | Race 2 `'pending'` stubs not excluded from live queries | **`'stub'` is a distinct status value**; live queries exclude `'stub'`; rollup trigger skips `status='stub'` rows | PLANV3-8 |
| 34 | Backfill `ON CONFLICT (idempotency_key)` referenced a column dropped from schema | **Fixed to `ON CONFLICT (shopify_shop_domain, shopify_order_id)`** | self-review (a) |
| 35 | `commission_payout_items.commission_movement_id` rename not in Phase 1 | **`ALTER TABLE ... RENAME COLUMN commission_ledger_entry_id TO commission_movement_id` added to Phase 1** | self-review (b) |
| 36 | `jsonb_strip_pii` referenced but not defined | **SQL function skeleton specified** with wildcard support for array paths | self-review (c) |
| 37 | Phase 3 said "drop dispatch" without listing job classes | **Explicit deletion list** with `rg` verification step in Phase 4 | self-review (d) |

---

## Future Rebuilds: When to Use Scientist

This pre-beta direct rebuild is *not* the playbook for future rebuilds. Once you have paying customers, the calculus flips. For any post-launch rebuild of `commerce.*` (e.g., partitioning, switching commission calculation logic, or splitting tenancy):

- Use [`daylerees/scientist-laravel`](https://github.com/daylerees/scientist-laravel) for shadow-read with diff logging.
- Run dual-write inside a single DB transaction (single-Postgres expand/contract).
- Sample at 10% → ramp to 100% over a week.
- SLO: <0.1% mismatch rate sustained for 48h before cutover.
- Keep dual-write enabled for one week post-cutover for rollback insurance.
- Per [Stripe's "Online migrations at scale"](https://stripe.com/blog/online-migrations) — dual-write + shadow-read is the canonical pattern, not either alone.

The full v2 plan (in git history) has the complete Scientist machinery spec'd if needed.

---

## Phase 1 Implementation Audit (2026-05-06)

After shipping Phase 0 ADR + Phase 1 schema/models/tests (1533 tests passing), a security and scalability audit was run for the 30-brand × multi-affiliate target. Key findings + status:

### Folded into Phase 1 (this PR)
- **scale-P0** Added `idx_orders_brand_affiliate_status_occurred` for the per-brand-per-affiliate breakdown query (was missing).
- **scale-P0** Added `idx_rollup_brand_day` and `idx_rollup_affiliate_day` to `brand_affiliate_rollup` — PK leads with `day`, so brand/affiliate-keyed range scans needed leading-column indexes.
- **scale-P0** Added BRIN indexes on `analytics.site_visits`, `analytics.link_clicks`, `analytics.cart_events` — at 30 brands × 50 affiliates × ~1k visits/affiliate/month, these tables reach ~90M rows in 5 years; BRIN on `occurred_at` is kilobytes vs gigabytes for B-tree.
- **sec-P2** Extended `RedactCustomerJob` and `RedactShopJob` to redact `commerce.orders.shopify_data` (full-nuke to `'{}'`), null `customer_id`, and redact `commerce.order_events.metadata` for affected orders. Matches existing `analytics.booking_events.raw_payload` pattern.

### Deferred to later phases (tracked here)
- **scale-P1** **BRIN correlation post-backfill** — Phase 2 backfill writes orders by `MIN(occurred_at)` per shopify_order_id, but the post-backfill verification step must check `pg_stats.correlation` ≥ 0.9 on the BRIN index and run `REINDEX INDEX CONCURRENTLY idx_orders_occurred_brin` if not. Already documented in Phase 2 description; called out here for completeness.
- **scale-P1** **`order_events` partition strategy** — at 1.5M orders/year × 4 events avg, this hits 30M rows in 5 years. Postgres handles this fine with the `(order_id, shopify_triggered_at)` index, but `pg_partman` monthly partitioning becomes attractive past 50M rows. **Trigger to revisit:** `commerce.order_events` row count crossing 10M.
- **scale-P1** **Cache lock contention at 30 brands** — `CacheLockService::rememberLocked` serializes per-key. With 30 brands × 2 hot keys = 60 keys, stampede protection works but lock-wait queues could form on simultaneous TTL expiry. Phase 3 mitigations: (a) enforce TTL jitter on every set (already in plan), (b) consider stale-while-revalidate to remove blocking waits.
- **sec-P2** **Manual `OrderEvent` rows can race on null `shopify_event_id`** — the unique partial index only enforces uniqueness when `shopify_event_id IS NOT NULL`. Two concurrent `source='manual'` events from staff actions could both insert. Phase 3 mitigation: application-level guard via `Cache::lock` keyed on `(order_id, source, intent)` for non-webhook event writes.
- **sec-P1** **`BrandAffiliateRollup` policy semantically loose under `CommissionPolicy`** — works because the rollup carries `brand_professional_id` and `affiliate_professional_id`, but the policy is designed for per-record entities, not aggregate rows. No security impact (party-select still correct); document in `app/Models/Commerce/BrandAffiliateRollup.php` class comment. Lower priority.

### Confirmed safe (no action)
- All trigger functions (`rollup_apply_delta`, `rollup_apply_clawback`, `order_items_diff`) use `SECURITY INVOKER` — no privilege escalation. No dynamic SQL or `EXECUTE` statements.
- CHECK constraint on `orders.status` is enforced regardless of role; `app_backend` BYPASSRLS doesn't bypass CHECK.
- RLS policies match the existing `commerce.commission_ledger_entries` party-select + staff-write pattern. Cross-tenant SELECTs blocked.
- Webhook idempotency via `X-Shopify-Event-Id` unique partial index works correctly for the webhook path.
- Trigger functions handle division-by-zero and stub rows correctly.

Audit artifacts: `audit-2026-05-05-analytics-rebuild-plan-v3.md` (planning doc audit), Phase 1 implementation audit synthesized inline above.

---

## Sources

### Primary docs
- [Shopify — Webhook best practices](https://shopify.dev/docs/apps/build/webhooks/best-practices)
- [Shopify — Ignore duplicate webhooks](https://shopify.dev/docs/apps/build/webhooks/ignore-duplicates)
- [Shopify Admin GraphQL — Order object (2025-01)](https://shopify.dev/docs/api/admin-graphql/2025-01/objects/Order)
- [Shopify Admin REST — Webhook resource (2025-01)](https://shopify.dev/docs/api/admin-rest/2025-01/resources/webhook)
- [Postgres — `INSERT ... ON CONFLICT`](https://www.postgresql.org/docs/current/sql-insert.html)
- [Postgres — BRIN indexes](https://www.postgresql.org/docs/current/brin-intro.html)
- [Supabase — Cron / pg_cron](https://supabase.com/docs/guides/cron)
- [Supabase — pg_cron debugging](https://supabase.com/docs/guides/troubleshooting/pgcron-debugging-guide-n1KTaz)
- [Laravel 12 — Cache (atomic locks)](https://laravel.com/docs/12.x/cache)
- [Laravel 12 — Eloquent chunking/lazy/cursor](https://laravel.com/docs/12.x/eloquent#chunking-results)

### Engineering blogs / community analysis
- [Citus Data — Materialized Views vs Rollup Tables](https://www.citusdata.com/blog/2018/10/31/materialized-views-vs-rollup-tables/)
- [Stripe — Online migrations at scale](https://stripe.com/blog/online-migrations)
- [TigerData — Postgres optimization treadmill](https://www.tigerdata.com/blog/postgres-optimization-treadmill)
- [Heap — When to avoid JSONB in PostgreSQL](https://www.heap.io/blog/when-to-avoid-jsonb-in-a-postgresql-schema)
- [Sigma Computing — TTL for analytics](https://www.sigmacomputing.com/blog/time-to-live-analytics)
- [Aman Maharshi — Cache stampede](https://www.amanmaharshi.com/blog/cache-stampede)

### Bug reports / known issues
- [Laravel issue #43627 — atomic locks unsafe (file driver)](https://github.com/laravel/framework/issues/43627)
- [shopify-app-js #603 — orders/updated before orders/create](https://github.com/Shopify/shopify-app-js/issues/603)

### Tooling for future rebuilds (post-launch)
- [GitHub Scientist](https://github.com/github/scientist) (Ruby original)
- [daylerees/scientist-laravel](https://github.com/daylerees/scientist-laravel)
