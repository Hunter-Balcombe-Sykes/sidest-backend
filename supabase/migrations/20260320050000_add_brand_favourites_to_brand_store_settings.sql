BEGIN;

ALTER TABLE retail.brand_store_settings
    ADD COLUMN IF NOT EXISTS favourite_brand_product_ids uuid[] NOT NULL DEFAULT '{}'::uuid[];

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bss_favourite_brand_product_ids_max_check'
          AND connamespace = 'retail'::regnamespace
    ) THEN
        ALTER TABLE retail.brand_store_settings
            ADD CONSTRAINT bss_favourite_brand_product_ids_max_check
            CHECK (coalesce(cardinality(favourite_brand_product_ids), 0) <= 10);
    END IF;
END $$;

COMMENT ON COLUMN retail.brand_store_settings.favourite_brand_product_ids
    IS 'Brand-managed favourite brand_product_id list used for default favourites.';

COMMIT;
