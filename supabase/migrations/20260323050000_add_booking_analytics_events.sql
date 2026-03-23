-- Capture booking analytics directly at checkout time.
-- This is independent from Square reporting and powers in-app booking analytics.

BEGIN;

CREATE TABLE IF NOT EXISTS analytics.booking_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES core.sites(id) ON DELETE CASCADE,
    brand_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    status text NOT NULL DEFAULT 'completed',
    source text NOT NULL DEFAULT 'site_booking_checkout',
    square_booking_id text NULL,
    square_payment_id text NULL,
    service_variation_id text NULL,
    service_name text NULL,
    payment_method text NULL,
    customer_name text NULL,
    customer_email text NULL,
    customer_phone text NULL,
    currency_code text NOT NULL DEFAULT 'AUD',
    amount_paid_cents integer NOT NULL DEFAULT 0,
    raw_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT booking_events_status_check CHECK (status IN ('accepted', 'pending', 'completed', 'cancelled', 'failed')),
    CONSTRAINT booking_events_amount_nonnegative CHECK (amount_paid_cents >= 0)
);

CREATE INDEX IF NOT EXISTS booking_events_professional_occurred_idx
    ON analytics.booking_events (professional_id, occurred_at DESC);

CREATE INDEX IF NOT EXISTS booking_events_brand_occurred_idx
    ON analytics.booking_events (brand_professional_id, occurred_at DESC);

CREATE INDEX IF NOT EXISTS booking_events_site_occurred_idx
    ON analytics.booking_events (site_id, occurred_at DESC);

CREATE UNIQUE INDEX IF NOT EXISTS booking_events_professional_booking_uq
    ON analytics.booking_events (professional_id, square_booking_id)
    WHERE square_booking_id IS NOT NULL;

DROP TRIGGER IF EXISTS trg_booking_events_set_updated_at ON analytics.booking_events;
CREATE TRIGGER trg_booking_events_set_updated_at
BEFORE UPDATE ON analytics.booking_events
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    EXECUTE 'GRANT USAGE ON SCHEMA analytics TO app_backend';
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.booking_events TO app_backend';
  END IF;
END $$;

COMMIT;
