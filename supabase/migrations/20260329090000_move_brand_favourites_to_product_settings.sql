BEGIN;

ALTER TABLE retail.brand_product_settings
    ADD COLUMN IF NOT EXISTS is_favourite BOOLEAN NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS favourite_sort_order INTEGER NOT NULL DEFAULT 0;

COMMENT ON COLUMN retail.brand_product_settings.is_favourite
    IS 'Whether this product is selected as a brand favourite (max 10 per brand).';
COMMENT ON COLUMN retail.brand_product_settings.favourite_sort_order
    IS 'Sort order for brand favourites when is_favourite = true.';

CREATE INDEX IF NOT EXISTS bps_professional_favourite
    ON retail.brand_product_settings (professional_id, is_favourite, favourite_sort_order);

-- Ensure every synced product row exists in settings before backfill.
INSERT INTO retail.brand_product_settings (
    id,
    professional_id,
    brand_product_id,
    shopify_product_id,
    is_featured,
    is_favourite,
    is_available,
    sort_order,
    favourite_sort_order,
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
    0,
    now(),
    now()
FROM retail.brand_products bp
LEFT JOIN retail.brand_product_settings bps
  ON bps.professional_id = bp.brand_professional_id
 AND bps.brand_product_id = bp.id
WHERE bps.id IS NULL;

-- Backfill from legacy retail.brand_store_settings.favourite_brand_product_ids
-- into row-based settings flags.
UPDATE retail.brand_product_settings
SET is_favourite = false,
    favourite_sort_order = 0,
    updated_at = now()
WHERE is_favourite = true;

WITH exploded AS (
    SELECT
        bss.professional_id,
        favourite_id::uuid AS brand_product_id,
        GREATEST(0, ordinality - 1) AS favourite_sort_order
    FROM retail.brand_store_settings bss
    CROSS JOIN LATERAL unnest(COALESCE(bss.favourite_brand_product_ids, '{}'::uuid[])) WITH ORDINALITY AS x(favourite_id, ordinality)
)
UPDATE retail.brand_product_settings bps
SET is_favourite = true,
    favourite_sort_order = exploded.favourite_sort_order,
    updated_at = now()
FROM exploded
WHERE bps.professional_id = exploded.professional_id
  AND bps.brand_product_id = exploded.brand_product_id;

CREATE OR REPLACE FUNCTION retail.enforce_brand_favourite_limit()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    favourite_count integer;
BEGIN
    IF COALESCE(NEW.is_favourite, false) IS DISTINCT FROM true THEN
        RETURN NEW;
    END IF;

    SELECT count(*)
      INTO favourite_count
      FROM retail.brand_product_settings bps
     WHERE bps.professional_id = NEW.professional_id
       AND bps.is_favourite = true
       AND (TG_OP <> 'UPDATE' OR bps.id <> NEW.id);

    IF favourite_count >= 10 THEN
        RAISE EXCEPTION 'A brand may have at most 10 favourite products.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_enforce_brand_favourite_limit ON retail.brand_product_settings;
CREATE TRIGGER trg_enforce_brand_favourite_limit
BEFORE INSERT OR UPDATE OF is_favourite, professional_id
ON retail.brand_product_settings
FOR EACH ROW
EXECUTE FUNCTION retail.enforce_brand_favourite_limit();

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
        is_favourite,
        is_available,
        sort_order,
        favourite_sort_order,
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

COMMIT;
