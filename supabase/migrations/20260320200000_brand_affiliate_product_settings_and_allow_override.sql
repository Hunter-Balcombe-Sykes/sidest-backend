-- Per-affiliate product pricing overrides and expanded override type support.
-- Introduces retail.brand_product_affiliate_settings for brand-controlled
-- per-affiliate commission/discount/price, expands override_type to include
-- 'allow', aligns selection validation with the current availability-only
-- product gate, and drops the unused
-- professional_selections.commission_override column.

BEGIN;

-- ============================================================
-- 1) retail.brand_product_affiliate_settings
--    Brand-controlled per-affiliate pricing overrides.
--    NULL fields fall back to the brand-level brand_product_settings value.
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.brand_product_affiliate_settings (
    id                        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_product_id          uuid NOT NULL REFERENCES retail.brand_products(id) ON DELETE CASCADE,
    commission_override       numeric(5,2) DEFAULT NULL
        CONSTRAINT bpas_commission_range CHECK (commission_override IS NULL OR (commission_override >= 0 AND commission_override <= 100)),
    discount_rate             numeric(5,2) DEFAULT NULL
        CONSTRAINT bpas_discount_range CHECK (discount_rate IS NULL OR (discount_rate >= 0 AND discount_rate <= 100)),
    custom_price              numeric(10,2) DEFAULT NULL
        CONSTRAINT bpas_custom_price_check CHECK (custom_price IS NULL OR custom_price >= 0),
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT bpas_unique UNIQUE (affiliate_professional_id, brand_product_id),
    CONSTRAINT bpas_different_parties CHECK (brand_professional_id <> affiliate_professional_id)
);

ALTER TABLE retail.brand_product_affiliate_settings OWNER TO postgres;

COMMENT ON TABLE retail.brand_product_affiliate_settings IS
    'Brand-controlled per-affiliate pricing overrides. NULL fields fall back to brand_product_settings values.';

COMMENT ON COLUMN retail.brand_product_affiliate_settings.commission_override IS
    'Affiliate-specific commission rate (0-100%). Highest-priority tier; overrides brand_product_settings.commission_override and brand_store_settings.default_commission_rate.';

COMMENT ON COLUMN retail.brand_product_affiliate_settings.discount_rate IS
    'Affiliate-specific discount rate (0-100%). Overrides brand_product_settings.discount_rate when set.';

COMMENT ON COLUMN retail.brand_product_affiliate_settings.custom_price IS
    'Affiliate-specific fixed price. Overrides brand_product_settings.custom_price when set.';

CREATE INDEX IF NOT EXISTS bpas_brand_affiliate_idx
    ON retail.brand_product_affiliate_settings (brand_professional_id, affiliate_professional_id);

DROP TRIGGER IF EXISTS trg_bpas_set_updated_at ON retail.brand_product_affiliate_settings;
CREATE TRIGGER trg_bpas_set_updated_at
BEFORE UPDATE ON retail.brand_product_affiliate_settings
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- Validation trigger: brand_product_id must belong to brand_professional_id;
-- affiliate must be connected to brand via brand_partner_links.
CREATE OR REPLACE FUNCTION retail.validate_brand_product_affiliate_setting()
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
        RAISE EXCEPTION 'Setting brand_professional_id does not match selected brand product owner.'
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

DROP TRIGGER IF EXISTS trg_validate_bpas ON retail.brand_product_affiliate_settings;
CREATE TRIGGER trg_validate_bpas
BEFORE INSERT OR UPDATE OF brand_professional_id, affiliate_professional_id, brand_product_id
ON retail.brand_product_affiliate_settings
FOR EACH ROW
EXECUTE FUNCTION retail.validate_brand_product_affiliate_setting();

-- ============================================================
-- 2) Expand override_type to include 'allow'
--    'deny' is enforced in selection validation and visibility
--    queries. 'allow' is persisted for explicit product access
--    state and future policy extension.
-- ============================================================
ALTER TABLE retail.brand_product_affiliate_overrides
    DROP CONSTRAINT IF EXISTS bpao_override_type_check;

ALTER TABLE retail.brand_product_affiliate_overrides
    ADD CONSTRAINT bpao_override_type_check CHECK (override_type IN ('deny', 'allow'));

COMMENT ON TABLE retail.brand_product_affiliate_overrides IS
    'Per-affiliate product access overrides. ''deny'' blocks product access for a specific affiliate. ''allow'' stores explicit affiliate access state.';

-- ============================================================
-- 3) Refresh selection trigger definition.
--    Keep deny precedence and enforce the current
--    availability-only storefront gate.
-- ============================================================
CREATE OR REPLACE FUNCTION retail.validate_professional_brand_selection()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    product_brand_professional_id uuid;
    product_shopify_product_id text;
    product_sync_active boolean;
    available_for_affiliates boolean;
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

    -- 'deny' override takes unconditional precedence.
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

    -- Product must be available for affiliates.
    SELECT EXISTS (
        SELECT 1
          FROM retail.brand_product_settings bps
         WHERE bps.professional_id = NEW.brand_professional_id
           AND bps.brand_product_id = NEW.brand_product_id
           AND COALESCE(bps.is_available, true) = true
    )
      INTO available_for_affiliates;

    IF NOT available_for_affiliates THEN
        RAISE EXCEPTION 'Selected product is not available for affiliates.'
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
-- 4) Drop unused professional_selections.commission_override.
--    Added in 20260309000000 but never written to or read.
--    Commission overrides are brand-controlled, not affiliate-
--    controlled, and live in brand_product_affiliate_settings.
-- ============================================================
DROP INDEX IF EXISTS retail.ps_commission_override;

ALTER TABLE retail.professional_selections
    DROP COLUMN IF EXISTS commission_override;

-- ============================================================
-- 5) Grants for runtime role
-- ============================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.brand_product_affiliate_settings TO app_backend';
    END IF;
END $$;

COMMIT;
