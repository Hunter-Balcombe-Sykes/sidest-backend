-- Remove infrastructure that is handled by Shopify and Stripe.
--
-- Shopify owns order reporting and exports. Stripe owns payout execution and
-- history. Comet's role is attribution, commission calculation, and a lightweight
-- analytics snapshot — not rebuilding what those platforms already provide.
--
-- Dropped:
--   analytics.brand_payout_daily   — Stripe tracks payout history
--   analytics.brand_region_daily   — Shopify has geographic breakdowns
--   analytics.brand_customer_daily — Shopify tracks new/returning customers
--   retail.payout_runs             — Stripe handles payout execution
--   retail.report_exports          — Shopify has data exports
--   retail.report_schedules        — Shopify has scheduled reports
--   retail.orders.financials_snapshot — redundant with the order's own columns
--   retail.sale_events             — pre-Shopify commission tracking, superseded by orders + commission_ledger_entries

BEGIN;

DROP TABLE IF EXISTS analytics.brand_payout_daily;
DROP TABLE IF EXISTS analytics.brand_region_daily;
DROP TABLE IF EXISTS analytics.brand_customer_daily;

-- Remove payout_run dependency before dropping payout_runs.
DROP INDEX IF EXISTS retail.commission_ledger_entries_payout_run_idx;
ALTER TABLE IF EXISTS retail.commission_ledger_entries
    DROP CONSTRAINT IF EXISTS commission_ledger_entries_payout_run_id_fkey;
ALTER TABLE IF EXISTS retail.commission_ledger_entries
    DROP COLUMN IF EXISTS payout_run_id;

DROP TABLE IF EXISTS retail.payout_runs;
DROP TABLE IF EXISTS retail.report_exports;
DROP TABLE IF EXISTS retail.report_schedules;
DROP TABLE IF EXISTS retail.sale_events;

ALTER TABLE retail.orders DROP COLUMN IF EXISTS financials_snapshot;

COMMIT;
