-- Remove legacy approval gate; availability is now the only visibility control.

BEGIN;

ALTER TABLE retail.brand_product_settings
    DROP COLUMN IF EXISTS is_approved;

CREATE OR REPLACE FUNCTION retail.ensure_brand_product_settings_row()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    INSERT INTO retail.brand_product_settings (
        id,
        professional_id,
        brand_product_id,
        shopify_product_id,
        is_featured,
        is_available,
        sort_order,
        created_at,
        updated_at
    )
    VALUES (
        gen_random_uuid(),
        NEW.brand_professional_id,
        NEW.id,
        NEW.shopify_product_id,
        false,
        true,
        0,
        now(),
        now()
    )
    ON CONFLICT (professional_id, brand_product_id)
    DO UPDATE SET
        shopify_product_id = EXCLUDED.shopify_product_id,
        updated_at = now();

    RETURN NEW;
END;
$$;

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

COMMIT;
