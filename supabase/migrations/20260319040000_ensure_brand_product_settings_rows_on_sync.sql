-- Ensure every synced brand product has a corresponding brand_product_settings row.
-- New rows default to is_approved=false as required by the approved-catalog model.

BEGIN;

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
        is_approved,
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

DROP TRIGGER IF EXISTS trg_ensure_brand_product_settings_row ON retail.brand_products;
CREATE TRIGGER trg_ensure_brand_product_settings_row
AFTER INSERT OR UPDATE OF shopify_product_id, brand_professional_id
ON retail.brand_products
FOR EACH ROW
EXECUTE FUNCTION retail.ensure_brand_product_settings_row();

INSERT INTO retail.brand_product_settings (
    id,
    professional_id,
    brand_product_id,
    shopify_product_id,
    is_approved,
    is_featured,
    is_available,
    sort_order,
    created_at,
    updated_at
)
SELECT
    gen_random_uuid(),
    bp.brand_professional_id,
    bp.id,
    bp.shopify_product_id,
    false,
    false,
    true,
    0,
    now(),
    now()
FROM retail.brand_products bp
LEFT JOIN retail.brand_product_settings bps
    ON bps.professional_id = bp.brand_professional_id
   AND bps.brand_product_id = bp.id
WHERE bps.id IS NULL
ON CONFLICT (professional_id, brand_product_id)
DO NOTHING;

COMMIT;
