BEGIN;

-- Widen financial cent-denominated columns from integer (32-bit, max ~$21M)
-- to bigint (64-bit) to prevent overflow at high revenue volumes.

ALTER TABLE analytics.brand_metrics_hourly
    ALTER COLUMN gross_cents TYPE bigint,
    ALTER COLUMN refunded_cents TYPE bigint,
    ALTER COLUMN returned_cents TYPE bigint,
    ALTER COLUMN net_cents TYPE bigint,
    ALTER COLUMN commission_net_cents TYPE bigint;

ALTER TABLE analytics.professional_metrics_hourly
    ALTER COLUMN gross_cents TYPE bigint,
    ALTER COLUMN refunded_cents TYPE bigint,
    ALTER COLUMN returned_cents TYPE bigint,
    ALTER COLUMN net_cents TYPE bigint,
    ALTER COLUMN commission_accrued_cents TYPE bigint,
    ALTER COLUMN commission_reversed_cents TYPE bigint,
    ALTER COLUMN commission_paid_cents TYPE bigint;

ALTER TABLE analytics.booking_metrics_hourly
    ALTER COLUMN total_spent_cents TYPE bigint;

ALTER TABLE analytics.booking_metrics_daily
    ALTER COLUMN total_spent_cents TYPE bigint;

COMMIT;
