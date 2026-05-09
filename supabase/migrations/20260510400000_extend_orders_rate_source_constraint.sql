BEGIN;

-- Today rate_source has a DEFAULT but no CHECK. Lock the enum so 'pending'
-- (introduced for out-of-bounds metafields) is the only new admissible value
-- and typos can't drift the field.
ALTER TABLE commerce.orders
    DROP CONSTRAINT IF EXISTS chk_orders_rate_source;

ALTER TABLE commerce.orders
    ADD CONSTRAINT chk_orders_rate_source
    CHECK (rate_source IN
        ('product_metafield','metafield_override','brand_default','platform_default','manual','pending'));

COMMIT;

-- DOWN:
-- BEGIN;
-- ALTER TABLE commerce.orders DROP CONSTRAINT IF EXISTS chk_orders_rate_source;
-- COMMIT;
