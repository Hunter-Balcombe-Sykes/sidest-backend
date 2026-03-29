-- Enable order attribution history tracking.
--
-- Problem: UNIQUE (order_id) means each model version change silently overwrites
-- the previous attribution record, losing audit history needed for commission
-- dispute resolution.
--
-- Fix: drop the single-row-per-order constraint and replace it with a composite
-- unique on (order_id, created_at) so multiple attribution versions can be stored.
-- A separate index on (order_id, created_at DESC) lets queries efficiently fetch
-- the latest attribution for an order.
--
-- Application code fetching attribution for an order MUST add
-- ->orderByDesc('created_at')->first() to retrieve the latest version.

BEGIN;

-- Drop the constraint that limited one attribution per order.
ALTER TABLE retail.order_attributions
    DROP CONSTRAINT IF EXISTS order_attributions_order_unique;

-- Ensure created_at is non-null for all existing rows (it already has a default).
UPDATE retail.order_attributions
    SET created_at = now()
    WHERE created_at IS NULL;

ALTER TABLE retail.order_attributions
    ALTER COLUMN created_at SET NOT NULL,
    ALTER COLUMN created_at SET DEFAULT now();

COMMIT;

-- Unique per (order, timestamp) — prevents duplicate writes at the same instant.
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS order_attributions_order_created_uq
    ON retail.order_attributions (order_id, created_at);

-- Fast "latest attribution for order" lookup.
CREATE INDEX CONCURRENTLY IF NOT EXISTS order_attributions_order_latest_idx
    ON retail.order_attributions (order_id, created_at DESC);
