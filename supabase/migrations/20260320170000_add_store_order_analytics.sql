-- Store order analytics captured at checkout time (independent from Shopify reporting)

CREATE TABLE IF NOT EXISTS analytics.store_order_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES core.sites(id) ON DELETE CASCADE,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    source text NOT NULL DEFAULT 'site_checkout',
    subdomain text NULL,
    payment_method text NULL,
    customer_name text NULL,
    customer_email text NULL,
    customer_phone text NULL,
    order_name text NULL,
    draft_order_id text NULL,
    order_id text NULL,
    currency_code text NOT NULL DEFAULT 'AUD',
    order_value_cents integer NOT NULL DEFAULT 0,
    line_item_count integer NOT NULL DEFAULT 0,
    raw_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS analytics.store_order_event_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id uuid NOT NULL REFERENCES analytics.store_order_events(id) ON DELETE CASCADE,
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES core.sites(id) ON DELETE CASCADE,
    brand_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    brand_product_id uuid NULL REFERENCES retail.brand_products(id) ON DELETE SET NULL,
    shopify_product_id text NOT NULL,
    shopify_variant_id text NULL,
    title text NULL,
    variant_title text NULL,
    quantity integer NOT NULL DEFAULT 1,
    unit_price_cents integer NULL,
    line_total_cents integer NULL,
    currency_code text NOT NULL DEFAULT 'AUD',
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS store_order_events_professional_occurred_idx
    ON analytics.store_order_events (professional_id, occurred_at DESC);

CREATE INDEX IF NOT EXISTS store_order_events_site_occurred_idx
    ON analytics.store_order_events (site_id, occurred_at DESC);

CREATE INDEX IF NOT EXISTS store_order_events_order_id_idx
    ON analytics.store_order_events (order_id);

CREATE INDEX IF NOT EXISTS store_order_event_items_event_idx
    ON analytics.store_order_event_items (event_id);

CREATE INDEX IF NOT EXISTS store_order_event_items_professional_occurred_idx
    ON analytics.store_order_event_items (professional_id, created_at DESC);

CREATE INDEX IF NOT EXISTS store_order_event_items_brand_occurred_idx
    ON analytics.store_order_event_items (brand_professional_id, created_at DESC);

CREATE INDEX IF NOT EXISTS store_order_event_items_shopify_product_idx
    ON analytics.store_order_event_items (shopify_product_id);

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    EXECUTE 'GRANT USAGE ON SCHEMA analytics TO app_backend';
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.store_order_events TO app_backend';
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.store_order_event_items TO app_backend';
  END IF;
END $$;
