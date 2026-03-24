-- Activate affiliate 'allow' overrides as a bypass for brand-level product availability.
-- 'deny' overrides retain unconditional precedence.

BEGIN;

CREATE OR REPLACE FUNCTION retail.validate_professional_brand_selection()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    product_brand_professional_id uuid;
    product_shopify_product_id text;
    product_sync_active boolean;
    has_allow_override boolean;
    available_for_affiliate boolean;
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

    -- 'allow' override bypasses the brand-level availability flag.
    SELECT EXISTS (
        SELECT 1
          FROM retail.brand_product_affiliate_overrides o
         WHERE o.affiliate_professional_id = NEW.professional_id
           AND o.brand_product_id = NEW.brand_product_id
           AND o.override_type = 'allow'
    )
      INTO has_allow_override;

    SELECT EXISTS (
        SELECT 1
          FROM retail.brand_product_settings bps
         WHERE bps.professional_id = NEW.brand_professional_id
           AND bps.brand_product_id = NEW.brand_product_id
           AND (has_allow_override OR COALESCE(bps.is_available, true) = true)
    )
      INTO available_for_affiliate;

    IF NOT available_for_affiliate THEN
        RAISE EXCEPTION 'Selected product is not available for this affiliate.'
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

COMMENT ON TABLE retail.brand_product_affiliate_overrides IS
    'Per-affiliate product access overrides. ''deny'' always blocks access; ''allow'' bypasses brand-level availability for a specific affiliate.';

COMMIT;
