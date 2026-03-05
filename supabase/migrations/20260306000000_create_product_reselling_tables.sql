-- Bed & Blade product reselling: curated catalog + professional selections + sale event log.
-- Comet curates products from Bed & Blade; professionals pick up to 6 to display on their site;
-- sales happen on Bed & Blade's Shopify; Bed & Blade pays commissions directly via their Stripe.

-- ============================================================
-- 0. Create the retail schema
-- ============================================================
CREATE SCHEMA IF NOT EXISTS retail;
ALTER SCHEMA retail OWNER TO postgres;
GRANT USAGE ON SCHEMA retail TO authenticated, anon;

-- ============================================================
-- 1. retail.products – Curated Bed & Blade product catalog
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.products (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    external_product_id text NOT NULL,
    title               text NOT NULL,
    description         text,
    brand               text NOT NULL DEFAULT 'Bed & Blade',
    price_cents         integer NOT NULL,
    currency_code       char(3) NOT NULL DEFAULT 'AUD',
    shopify_url         text NOT NULL,
    image_url           text,
    category            text,
    is_active           boolean NOT NULL DEFAULT true,
    sort_order          integer NOT NULL DEFAULT 0,
    metadata            jsonb DEFAULT '{}'::jsonb,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE retail.products OWNER TO postgres;

COMMENT ON TABLE retail.products IS 'Curated catalog of Bed & Blade products available for professional reselling';

CREATE UNIQUE INDEX IF NOT EXISTS products_external_id_uq
    ON retail.products(external_product_id);

CREATE INDEX IF NOT EXISTS products_active_sort
    ON retail.products(is_active, sort_order);

CREATE OR REPLACE FUNCTION retail.set_products_updated_at()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_products_set_updated_at ON retail.products;
CREATE TRIGGER trg_products_set_updated_at
BEFORE UPDATE ON retail.products
FOR EACH ROW EXECUTE FUNCTION retail.set_products_updated_at();

-- ============================================================
-- 2. retail.professional_selections
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.professional_selections (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    product_id          uuid NOT NULL REFERENCES retail.products(id) ON DELETE CASCADE,
    sort_order          integer NOT NULL DEFAULT 0,
    created_at          timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE retail.professional_selections OWNER TO postgres;

COMMENT ON TABLE retail.professional_selections IS 'Products selected by a professional to display on their Comet site (max 6)';

CREATE UNIQUE INDEX IF NOT EXISTS ps_professional_product_uq
    ON retail.professional_selections(professional_id, product_id);

CREATE INDEX IF NOT EXISTS ps_professional_sort
    ON retail.professional_selections(professional_id, sort_order);

CREATE OR REPLACE FUNCTION retail.enforce_max_selections()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    current_count integer;
BEGIN
    SELECT count(*) INTO current_count
    FROM retail.professional_selections
    WHERE professional_id = NEW.professional_id;

    IF current_count >= 6 THEN
        RAISE EXCEPTION 'Professional may select a maximum of 6 products'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_max_selections ON retail.professional_selections;
CREATE TRIGGER trg_enforce_max_selections
BEFORE INSERT ON retail.professional_selections
FOR EACH ROW EXECUTE FUNCTION retail.enforce_max_selections();

-- ============================================================
-- 3. retail.sale_events
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.sale_events (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    product_id          uuid NOT NULL REFERENCES retail.products(id) ON DELETE SET NULL,
    shopify_order_id    text,
    quantity            integer NOT NULL DEFAULT 1,
    sale_amount_cents   integer,
    currency_code       char(3) NOT NULL DEFAULT 'AUD',
    event_payload       jsonb DEFAULT '{}'::jsonb,
    recorded_at         timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE retail.sale_events OWNER TO postgres;

COMMENT ON TABLE retail.sale_events IS 'Log of product sales attributed to professionals (Bed & Blade pays commissions directly)';

CREATE INDEX IF NOT EXISTS se_professional_recorded
    ON retail.sale_events(professional_id, recorded_at DESC);

CREATE INDEX IF NOT EXISTS se_shopify_order
    ON retail.sale_events(shopify_order_id);

-- ============================================================
-- 4. Row Level Security
-- ============================================================
ALTER TABLE retail.products ENABLE ROW LEVEL SECURITY;

CREATE POLICY products_staff_all ON retail.products
    TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()));

CREATE POLICY products_pro_read_active ON retail.products
    FOR SELECT TO authenticated
    USING (is_active = true AND EXISTS (
        SELECT 1 FROM core.professionals WHERE professionals.auth_user_id = auth.uid() AND professionals.deleted_at IS NULL
    ));

CREATE POLICY products_anon_read_active ON retail.products
    FOR SELECT TO anon USING (is_active = true);

ALTER TABLE retail.professional_selections ENABLE ROW LEVEL SECURITY;

CREATE POLICY ps_pro_all ON retail.professional_selections
    TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY ps_staff_all ON retail.professional_selections
    TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()));

CREATE POLICY ps_anon_read_published ON retail.professional_selections
    FOR SELECT TO anon
    USING (EXISTS (SELECT 1 FROM core.sites s WHERE s.professional_id = professional_selections.professional_id AND s.is_published = true));

ALTER TABLE retail.sale_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY se_pro_read_own ON retail.sale_events
    FOR SELECT TO authenticated
    USING (professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY se_staff_all ON retail.sale_events
    TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()));

-- ============================================================
-- 5. Grants
-- ============================================================
GRANT SELECT ON retail.products TO authenticated, anon;
GRANT INSERT, UPDATE, DELETE ON retail.products TO authenticated;

GRANT SELECT ON retail.professional_selections TO authenticated, anon;
GRANT INSERT, UPDATE, DELETE ON retail.professional_selections TO authenticated;

GRANT SELECT ON retail.sale_events TO authenticated;
GRANT INSERT ON retail.sale_events TO authenticated;
