BEGIN;

ALTER TABLE retail.brand_store_settings
    ADD COLUMN IF NOT EXISTS default_affiliate_theme_id UUID REFERENCES core.themes(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS default_affiliate_product_ids UUID[] NOT NULL DEFAULT '{}';

COMMENT ON COLUMN retail.brand_store_settings.default_affiliate_theme_id IS
    'Theme assigned to new affiliates when they connect to this brand.';
COMMENT ON COLUMN retail.brand_store_settings.default_affiliate_product_ids IS
    'Up to 10 product IDs used as defaults for new affiliates (separate from favourite_brand_product_ids).';

COMMIT;
