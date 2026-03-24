-- Add customer tracking to affiliate analytics.
--
-- Comet's unique value here: Shopify tracks customers at the brand level,
-- but has no concept of "customers this influencer brought in". This fills
-- that gap — per-affiliate unique customer counts, new vs returning, per day.
--
-- Changes:
--   analytics.professional_customer_daily   — new: daily customer counts per affiliate
--   analytics.brand_influencer_daily        — add customers_count column

BEGIN;

-- ============================================================
-- 1) analytics.professional_customer_daily
-- ============================================================
CREATE TABLE IF NOT EXISTS analytics.professional_customer_daily (
    day date NOT NULL,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    timezone text NOT NULL,
    customers_count integer NOT NULL DEFAULT 0,
    new_customers_count integer NOT NULL DEFAULT 0,
    returning_customers_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, affiliate_professional_id, timezone)
);

CREATE INDEX IF NOT EXISTS professional_customer_daily_affiliate_day_idx
    ON analytics.professional_customer_daily (affiliate_professional_id, day DESC);

-- ============================================================
-- 2) Add customers_count to brand_influencer_daily
-- ============================================================
ALTER TABLE analytics.brand_influencer_daily
    ADD COLUMN IF NOT EXISTS customers_count integer NOT NULL DEFAULT 0;

-- ============================================================
-- 3) Grants
-- ============================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.professional_customer_daily TO app_backend';
    END IF;
END $$;

COMMIT;
