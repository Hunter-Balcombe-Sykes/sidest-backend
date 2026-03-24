-- Brand-approved affiliate commerce foundation.
-- Introduces brand-scoped synced catalog, affiliate deny overrides,
-- enterprise-to-brand management links, and strict brand-product selections.

BEGIN;

-- ============================================================
-- 1) retail.brand_products (full synced catalog per brand)
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.brand_products (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    enterprise_id       uuid NULL REFERENCES core.enterprises(id) ON DELETE SET NULL,
    shopify_product_id  text NOT NULL,
    title               text NOT NULL,
    handle              text,
    product_url         text,
    image_url           text,
    price_cents         integer,
    currency_code       char(3) NOT NULL DEFAULT 'AUD',
    shopify_status      text NOT NULL DEFAULT 'active',
    is_sync_active      boolean NOT NULL DEFAULT true,
    last_synced_at      timestamptz,
    metadata            jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_products_price_cents_check CHECK (price_cents IS NULL OR price_cents >= 0),
    CONSTRAINT brand_products_status_check CHECK (shopify_status IN ('active', 'archived', 'draft', 'deleted', 'unknown'))
);

ALTER TABLE retail.brand_products OWNER TO postgres;

COMMENT ON TABLE retail.brand_products IS 'Full Shopify-synced catalog for each brand professional account.';

CREATE UNIQUE INDEX IF NOT EXISTS brand_products_brand_shopify_uq
    ON retail.brand_products (brand_professional_id, shopify_product_id);

CREATE INDEX IF NOT EXISTS brand_products_brand_sync_idx
    ON retail.brand_products (brand_professional_id, is_sync_active);

CREATE INDEX IF NOT EXISTS brand_products_enterprise_sync_idx
    ON retail.brand_products (enterprise_id, is_sync_active);

DROP TRIGGER IF EXISTS trg_brand_products_set_updated_at ON retail.brand_products;
CREATE TRIGGER trg_brand_products_set_updated_at
BEFORE UPDATE ON retail.brand_products
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- Backfill brand_products from existing per-product brand settings.
INSERT INTO retail.brand_products (
    id,
    brand_professional_id,
    enterprise_id,
    shopify_product_id,
    title,
    currency_code,
    shopify_status,
    is_sync_active,
    metadata,
    created_at,
    updated_at,
    last_synced_at
)
SELECT
    gen_random_uuid(),
    bps.professional_id,
    p.primary_enterprise_id,
    bps.shopify_product_id,
    'Shopify Product ' || right(bps.shopify_product_id, 12),
    'AUD',
    'unknown',
    true,
    jsonb_build_object('source', 'backfill_brand_product_settings'),
    now(),
    now(),
    now()
FROM retail.brand_product_settings bps
LEFT JOIN core.professionals p
    ON p.id = bps.professional_id
WHERE COALESCE(trim(bps.shopify_product_id), '') <> ''
ON CONFLICT (brand_professional_id, shopify_product_id)
DO UPDATE SET
    updated_at = now();

-- ============================================================
-- 2) Evolve retail.brand_product_settings
-- ============================================================
ALTER TABLE retail.brand_product_settings
    ADD COLUMN IF NOT EXISTS brand_product_id uuid,
    ADD COLUMN IF NOT EXISTS is_approved boolean NOT NULL DEFAULT false;

UPDATE retail.brand_product_settings bps
SET brand_product_id = bp.id
FROM retail.brand_products bp
WHERE bps.brand_product_id IS NULL
  AND bp.brand_professional_id = bps.professional_id
  AND bp.shopify_product_id = bps.shopify_product_id;

-- Hard-delete unresolved rows (locked decision).
DELETE FROM retail.brand_product_settings
WHERE brand_product_id IS NULL;

ALTER TABLE retail.brand_product_settings
    ALTER COLUMN brand_product_id SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'brand_product_settings_brand_product_id_fkey'
          AND connamespace = 'retail'::regnamespace
    ) THEN
        ALTER TABLE retail.brand_product_settings
            ADD CONSTRAINT brand_product_settings_brand_product_id_fkey
            FOREIGN KEY (brand_product_id)
            REFERENCES retail.brand_products(id)
            ON DELETE CASCADE;
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS bps_professional_brand_product_uq
    ON retail.brand_product_settings (professional_id, brand_product_id);

-- Enforce max 10 featured rows per brand.
CREATE OR REPLACE FUNCTION retail.enforce_brand_featured_limit()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    featured_count integer;
BEGIN
    IF COALESCE(NEW.is_featured, false) IS DISTINCT FROM true THEN
        RETURN NEW;
    END IF;

    SELECT count(*)
      INTO featured_count
      FROM retail.brand_product_settings bps
     WHERE bps.professional_id = NEW.professional_id
       AND bps.is_featured = true
       AND (TG_OP <> 'UPDATE' OR bps.id <> NEW.id);

    IF featured_count >= 10 THEN
        RAISE EXCEPTION 'A brand may have at most 10 featured products.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_brand_featured_limit ON retail.brand_product_settings;
CREATE TRIGGER trg_enforce_brand_featured_limit
BEFORE INSERT OR UPDATE OF is_featured, professional_id
ON retail.brand_product_settings
FOR EACH ROW
EXECUTE FUNCTION retail.enforce_brand_featured_limit();

-- ============================================================
-- 3) retail.brand_product_affiliate_overrides (restrict-only)
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.brand_product_affiliate_overrides (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id   uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_product_id        uuid NOT NULL REFERENCES retail.brand_products(id) ON DELETE CASCADE,
    override_type           text NOT NULL DEFAULT 'deny',
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT bpao_override_type_check CHECK (override_type IN ('deny')),
    CONSTRAINT bpao_not_self_check CHECK (affiliate_professional_id <> brand_professional_id)
);

ALTER TABLE retail.brand_product_affiliate_overrides OWNER TO postgres;

COMMENT ON TABLE retail.brand_product_affiliate_overrides IS 'Per-affiliate deny overrides on top of a brand''s globally approved sellable products.';

CREATE UNIQUE INDEX IF NOT EXISTS bpao_affiliate_product_uq
    ON retail.brand_product_affiliate_overrides (affiliate_professional_id, brand_product_id);

CREATE INDEX IF NOT EXISTS bpao_brand_affiliate_idx
    ON retail.brand_product_affiliate_overrides (brand_professional_id, affiliate_professional_id);

DROP TRIGGER IF EXISTS trg_bpao_set_updated_at ON retail.brand_product_affiliate_overrides;
CREATE TRIGGER trg_bpao_set_updated_at
BEFORE UPDATE ON retail.brand_product_affiliate_overrides
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE FUNCTION retail.validate_brand_product_affiliate_override()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    product_brand_professional_id uuid;
BEGIN
    SELECT bp.brand_professional_id
      INTO product_brand_professional_id
      FROM retail.brand_products bp
     WHERE bp.id = NEW.brand_product_id;

    IF product_brand_professional_id IS NULL THEN
        RAISE EXCEPTION 'Selected brand product does not exist.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF product_brand_professional_id <> NEW.brand_professional_id THEN
        RAISE EXCEPTION 'Override brand_professional_id does not match selected brand product owner.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM core.brand_partner_links l
         WHERE l.affiliate_professional_id = NEW.affiliate_professional_id
           AND l.brand_professional_id = NEW.brand_professional_id
    ) THEN
        RAISE EXCEPTION 'Affiliate is not connected to this brand.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_bpao ON retail.brand_product_affiliate_overrides;
CREATE TRIGGER trg_validate_bpao
BEFORE INSERT OR UPDATE OF brand_professional_id, affiliate_professional_id, brand_product_id
ON retail.brand_product_affiliate_overrides
FOR EACH ROW
EXECUTE FUNCTION retail.validate_brand_product_affiliate_override();

-- ============================================================
-- 4) Evolve retail.professional_selections for brand_product_id
-- ============================================================
ALTER TABLE retail.professional_selections
    ADD COLUMN IF NOT EXISTS brand_product_id uuid,
    ADD COLUMN IF NOT EXISTS brand_professional_id uuid;

WITH deterministic_candidates AS (
    SELECT
        ps.id AS selection_id,
        (array_agg(bp.id ORDER BY bp.id))[1] AS brand_product_id,
        (array_agg(bp.brand_professional_id ORDER BY bp.id))[1] AS brand_professional_id,
        count(*) AS candidate_count
    FROM retail.professional_selections ps
    JOIN core.brand_partner_links bpl
      ON bpl.affiliate_professional_id = ps.professional_id
    JOIN retail.brand_products bp
      ON bp.brand_professional_id = bpl.brand_professional_id
     AND bp.shopify_product_id = ps.shopify_product_id
    WHERE ps.brand_product_id IS NULL
    GROUP BY ps.id
    HAVING count(*) = 1
)
UPDATE retail.professional_selections ps
SET brand_product_id = c.brand_product_id,
    brand_professional_id = c.brand_professional_id
FROM deterministic_candidates c
WHERE ps.id = c.selection_id;

UPDATE retail.professional_selections ps
SET brand_product_id = bp.id
FROM retail.brand_products bp
WHERE ps.brand_product_id IS NULL
  AND ps.brand_professional_id IS NOT NULL
  AND bp.brand_professional_id = ps.brand_professional_id
  AND bp.shopify_product_id = ps.shopify_product_id;

UPDATE retail.professional_selections ps
SET brand_professional_id = bp.brand_professional_id
FROM retail.brand_products bp
WHERE ps.brand_product_id = bp.id
  AND ps.brand_professional_id IS NULL;

-- Hard-delete unresolved rows (locked decision).
DELETE FROM retail.professional_selections
WHERE brand_product_id IS NULL
   OR brand_professional_id IS NULL;

ALTER TABLE retail.professional_selections
    ALTER COLUMN brand_product_id SET NOT NULL,
    ALTER COLUMN brand_professional_id SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'professional_selections_brand_product_id_fkey'
          AND connamespace = 'retail'::regnamespace
    ) THEN
        ALTER TABLE retail.professional_selections
            ADD CONSTRAINT professional_selections_brand_product_id_fkey
            FOREIGN KEY (brand_product_id)
            REFERENCES retail.brand_products(id)
            ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'professional_selections_brand_professional_id_fkey'
          AND connamespace = 'retail'::regnamespace
    ) THEN
        ALTER TABLE retail.professional_selections
            ADD CONSTRAINT professional_selections_brand_professional_id_fkey
            FOREIGN KEY (brand_professional_id)
            REFERENCES core.professionals(id)
            ON DELETE CASCADE;
    END IF;
END $$;

DROP INDEX IF EXISTS retail.ps_professional_product_uq;
CREATE UNIQUE INDEX IF NOT EXISTS ps_professional_brand_product_uq
    ON retail.professional_selections (professional_id, brand_product_id);

CREATE INDEX IF NOT EXISTS ps_professional_brand_sort_idx
    ON retail.professional_selections (professional_id, brand_professional_id, sort_order);

CREATE OR REPLACE FUNCTION retail.validate_professional_brand_selection()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    product_brand_professional_id uuid;
    product_shopify_product_id text;
    product_sync_active boolean;
    approved_and_available boolean;
BEGIN
    SELECT bp.brand_professional_id, bp.shopify_product_id, bp.is_sync_active
      INTO product_brand_professional_id, product_shopify_product_id, product_sync_active
      FROM retail.brand_products bp
     WHERE bp.id = NEW.brand_product_id;

    IF product_brand_professional_id IS NULL THEN
        RAISE EXCEPTION 'Selected brand product does not exist.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF product_brand_professional_id <> NEW.brand_professional_id THEN
        RAISE EXCEPTION 'Selected brand product does not belong to provided brand_professional_id.'
            USING ERRCODE = 'check_violation';
    END IF;

    NEW.shopify_product_id = product_shopify_product_id;

    IF product_sync_active IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'Selected product is not sync-active and cannot be sold.'
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT EXISTS (
        SELECT 1
          FROM retail.brand_product_settings bps
         WHERE bps.professional_id = NEW.brand_professional_id
           AND bps.brand_product_id = NEW.brand_product_id
           AND bps.is_approved = true
           AND COALESCE(bps.is_available, true) = true
    )
      INTO approved_and_available;

    IF NOT approved_and_available THEN
        RAISE EXCEPTION 'Selected product is not approved/available for affiliates.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM core.brand_partner_links l
         WHERE l.affiliate_professional_id = NEW.professional_id
           AND l.brand_professional_id = NEW.brand_professional_id
    ) THEN
        RAISE EXCEPTION 'Professional is not connected to selected brand.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM retail.brand_product_affiliate_overrides o
         WHERE o.affiliate_professional_id = NEW.professional_id
           AND o.brand_product_id = NEW.brand_product_id
           AND o.override_type = 'deny'
    ) THEN
        RAISE EXCEPTION 'Selected product is denied for this affiliate.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_professional_brand_selection ON retail.professional_selections;
CREATE TRIGGER trg_validate_professional_brand_selection
BEFORE INSERT OR UPDATE OF professional_id, brand_product_id, brand_professional_id, shopify_product_id
ON retail.professional_selections
FOR EACH ROW
EXECUTE FUNCTION retail.validate_professional_brand_selection();

-- ============================================================
-- 5) core.enterprise_brand_links (enterprise manages many brands)
-- ============================================================
CREATE TABLE IF NOT EXISTS core.enterprise_brand_links (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    enterprise_id       uuid NOT NULL REFERENCES core.enterprises(id) ON DELETE CASCADE,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    role                text NOT NULL DEFAULT 'manager',
    status              text NOT NULL DEFAULT 'active',
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT enterprise_brand_links_role_check CHECK (role IN ('owner', 'manager')),
    CONSTRAINT enterprise_brand_links_status_check CHECK (status IN ('active', 'inactive'))
);

ALTER TABLE core.enterprise_brand_links OWNER TO postgres;

COMMENT ON TABLE core.enterprise_brand_links IS 'Links distributor enterprises to managed brand professional accounts.';

CREATE UNIQUE INDEX IF NOT EXISTS ebl_enterprise_brand_uq
    ON core.enterprise_brand_links (enterprise_id, brand_professional_id);

CREATE INDEX IF NOT EXISTS ebl_brand_idx
    ON core.enterprise_brand_links (brand_professional_id);

CREATE INDEX IF NOT EXISTS ebl_enterprise_status_idx
    ON core.enterprise_brand_links (enterprise_id, status);

DROP TRIGGER IF EXISTS trg_enterprise_brand_links_set_updated_at ON core.enterprise_brand_links;
CREATE TRIGGER trg_enterprise_brand_links_set_updated_at
BEFORE UPDATE ON core.enterprise_brand_links
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE FUNCTION core.validate_enterprise_brand_link()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    linked_brand_type text;
    linked_enterprise_type text;
BEGIN
    SELECT p.professional_type
      INTO linked_brand_type
      FROM core.professionals p
     WHERE p.id = NEW.brand_professional_id
       AND p.deleted_at IS NULL;

    IF linked_brand_type IS DISTINCT FROM 'brand' THEN
        RAISE EXCEPTION 'enterprise_brand_links.brand_professional_id must reference professional_type = brand'
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT e.enterprise_type
      INTO linked_enterprise_type
      FROM core.enterprises e
     WHERE e.id = NEW.enterprise_id
       AND e.deleted_at IS NULL;

    IF linked_enterprise_type IS DISTINCT FROM 'distributor' THEN
        RAISE EXCEPTION 'enterprise_brand_links.enterprise_id must reference enterprise_type = distributor'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_enterprise_brand_link ON core.enterprise_brand_links;
CREATE TRIGGER trg_validate_enterprise_brand_link
BEFORE INSERT OR UPDATE OF enterprise_id, brand_professional_id
ON core.enterprise_brand_links
FOR EACH ROW
EXECUTE FUNCTION core.validate_enterprise_brand_link();

-- ============================================================
-- 6) Expand enterprise type to include distributor
-- ============================================================
ALTER TABLE core.enterprises
    DROP CONSTRAINT IF EXISTS enterprises_type_check;

ALTER TABLE core.enterprises
    ADD CONSTRAINT enterprises_type_check
    CHECK (enterprise_type IN ('promoter', 'salon', 'barbershop', 'distributor'));

COMMENT ON COLUMN core.enterprises.enterprise_type IS
    'Top-level business entity category (promoter, salon, barbershop, distributor).';

-- ============================================================
-- 7) Grants for runtime role
-- ============================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA retail TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.brand_products TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.brand_product_affiliate_overrides TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.brand_product_settings TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.professional_selections TO app_backend';

        EXECUTE 'GRANT USAGE ON SCHEMA core TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE core.enterprise_brand_links TO app_backend';
    END IF;
END $$;

COMMIT;
