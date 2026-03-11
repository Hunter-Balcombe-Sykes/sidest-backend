-- Drop the local retail.products catalog table and simplify selections + sale_events
-- to reference Shopify product IDs directly. Product data is fetched from Shopify API at runtime.

BEGIN;

-- ============================================================
-- 1. Drop dependent tables first (they FK to retail.products)
-- ============================================================
DROP TABLE IF EXISTS retail.sale_events CASCADE;
DROP TABLE IF EXISTS retail.professional_selections CASCADE;

-- ============================================================
-- 2. Drop the products table and its trigger/function
-- ============================================================
DROP TRIGGER IF EXISTS trg_products_set_updated_at ON retail.products;
DROP FUNCTION IF EXISTS retail.set_products_updated_at();
DROP TABLE IF EXISTS retail.products CASCADE;

-- The enforce_max_selections function was also created in the old migration
DROP FUNCTION IF EXISTS retail.enforce_max_selections();


-- ============================================================
-- 3. Recreate retail.professional_selections (Shopify product ID, no FK)
-- ============================================================
CREATE TABLE retail.professional_selections (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    shopify_product_id  text NOT NULL,                          -- Bed & Blade Shopify product ID (e.g. "gid://shopify/Product/123")
    sort_order          integer NOT NULL DEFAULT 0,
    created_at          timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE retail.professional_selections OWNER TO postgres;

COMMENT ON TABLE retail.professional_selections
    IS 'Shopify product IDs selected by a professional to display on their Comet site (max 6). Product details fetched from Shopify API at runtime.';

-- One selection per Shopify product per professional
CREATE UNIQUE INDEX ps_professional_product_uq
    ON retail.professional_selections(professional_id, shopify_product_id);

CREATE INDEX ps_professional_sort
    ON retail.professional_selections(professional_id, sort_order);

-- Enforce max 6 selections per professional
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

CREATE TRIGGER trg_enforce_max_selections
BEFORE INSERT ON retail.professional_selections
FOR EACH ROW EXECUTE FUNCTION retail.enforce_max_selections();


-- ============================================================
-- 4. Recreate retail.sale_events (Shopify product ID, no FK)
-- ============================================================
CREATE TABLE retail.sale_events (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    shopify_product_id  text,                                   -- which Shopify product was sold
    shopify_order_id    text,                                   -- Bed & Blade's Shopify order ref
    quantity            integer NOT NULL DEFAULT 1,
    sale_amount_cents   integer,                                -- total sale value in cents
    currency_code       char(3) NOT NULL DEFAULT 'AUD',
    event_payload       jsonb DEFAULT '{}'::jsonb,              -- raw webhook / event data
    recorded_at         timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE retail.sale_events OWNER TO postgres;

COMMENT ON TABLE retail.sale_events
    IS 'Log of product sales attributed to professionals (Bed & Blade pays commissions directly)';

CREATE INDEX se_professional_recorded
    ON retail.sale_events(professional_id, recorded_at DESC);

CREATE INDEX se_shopify_order
    ON retail.sale_events(shopify_order_id);


-- ============================================================
-- 5. Row Level Security
-- ============================================================

-- professional_selections
ALTER TABLE retail.professional_selections ENABLE ROW LEVEL SECURITY;

CREATE POLICY ps_pro_all ON retail.professional_selections
    TO authenticated
    USING (professional_id = (
        SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ))
    WITH CHECK (professional_id = (
        SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

CREATE POLICY ps_staff_all ON retail.professional_selections
    TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()));

CREATE POLICY ps_anon_read_published ON retail.professional_selections
    FOR SELECT TO anon
    USING (EXISTS (
        SELECT 1 FROM core.sites s
        WHERE s.professional_id = professional_selections.professional_id
          AND s.is_published = true
    ));

-- sale_events
ALTER TABLE retail.sale_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY se_pro_read_own ON retail.sale_events
    FOR SELECT TO authenticated
    USING (professional_id = (
        SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

CREATE POLICY se_staff_all ON retail.sale_events
    TO authenticated
    USING (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.comet_staff WHERE comet_staff.auth_user_id = auth.uid()));


-- ============================================================
-- 6. Grants
-- ============================================================
GRANT SELECT ON retail.professional_selections TO authenticated, anon;
GRANT INSERT, UPDATE, DELETE ON retail.professional_selections TO authenticated;

GRANT SELECT ON retail.sale_events TO authenticated;
GRANT INSERT ON retail.sale_events TO authenticated;

COMMIT;
