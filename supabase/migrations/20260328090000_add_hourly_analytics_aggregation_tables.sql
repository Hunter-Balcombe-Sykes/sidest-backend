BEGIN;

-- ============================================================
-- Hourly aggregates for commerce/store analytics
-- ============================================================
CREATE TABLE IF NOT EXISTS analytics.brand_metrics_hourly (
    hour_start timestamptz NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (hour_start, brand_professional_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_metrics_hourly_brand_hour_idx
    ON analytics.brand_metrics_hourly (brand_professional_id, hour_start DESC);

CREATE TABLE IF NOT EXISTS analytics.professional_metrics_hourly (
    hour_start timestamptz NOT NULL,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_accrued_cents integer NOT NULL DEFAULT 0,
    commission_reversed_cents integer NOT NULL DEFAULT 0,
    commission_paid_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (hour_start, affiliate_professional_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS professional_metrics_hourly_affiliate_hour_idx
    ON analytics.professional_metrics_hourly (affiliate_professional_id, hour_start DESC);

-- ============================================================
-- Hourly + daily aggregates for site analytics
-- ============================================================
CREATE TABLE IF NOT EXISTS analytics.site_metrics_hourly (
    hour_start timestamptz NOT NULL,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES core.sites(id) ON DELETE CASCADE,
    timezone text NOT NULL,
    visits_count integer NOT NULL DEFAULT 0,
    unique_visitors integer NOT NULL DEFAULT 0,
    clicks_count integer NOT NULL DEFAULT 0,
    unique_clickers integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (hour_start, professional_id, site_id, timezone)
);

CREATE INDEX IF NOT EXISTS site_metrics_hourly_professional_hour_idx
    ON analytics.site_metrics_hourly (professional_id, hour_start DESC);

CREATE INDEX IF NOT EXISTS site_metrics_hourly_site_hour_idx
    ON analytics.site_metrics_hourly (site_id, hour_start DESC);

CREATE TABLE IF NOT EXISTS analytics.site_metrics_daily (
    day date NOT NULL,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES core.sites(id) ON DELETE CASCADE,
    timezone text NOT NULL,
    visits_count integer NOT NULL DEFAULT 0,
    unique_visitors integer NOT NULL DEFAULT 0,
    clicks_count integer NOT NULL DEFAULT 0,
    unique_clickers integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, professional_id, site_id, timezone)
);

CREATE INDEX IF NOT EXISTS site_metrics_daily_professional_day_idx
    ON analytics.site_metrics_daily (professional_id, day DESC);

CREATE INDEX IF NOT EXISTS site_metrics_daily_site_day_idx
    ON analytics.site_metrics_daily (site_id, day DESC);

-- ============================================================
-- Hourly + daily aggregates for booking analytics
-- ============================================================
CREATE TABLE IF NOT EXISTS analytics.booking_metrics_hourly (
    hour_start timestamptz NOT NULL,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    bookings_count integer NOT NULL DEFAULT 0,
    total_spent_cents integer NOT NULL DEFAULT 0,
    paid_bookings_count integer NOT NULL DEFAULT 0,
    customers_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (hour_start, professional_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS booking_metrics_hourly_professional_hour_idx
    ON analytics.booking_metrics_hourly (professional_id, hour_start DESC);

CREATE TABLE IF NOT EXISTS analytics.booking_metrics_daily (
    day date NOT NULL,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    bookings_count integer NOT NULL DEFAULT 0,
    total_spent_cents integer NOT NULL DEFAULT 0,
    paid_bookings_count integer NOT NULL DEFAULT 0,
    customers_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, professional_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS booking_metrics_daily_professional_day_idx
    ON analytics.booking_metrics_daily (professional_id, day DESC);

-- ============================================================
-- Runtime grants
-- ============================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA analytics TO app_backend';

        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_metrics_hourly TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.professional_metrics_hourly TO app_backend';

        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.site_metrics_hourly TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.site_metrics_daily TO app_backend';

        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.booking_metrics_hourly TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.booking_metrics_daily TO app_backend';
    END IF;
END $$;

COMMIT;
