-- Add availability flag and custom price override to retail.brand_product_settings

ALTER TABLE retail.brand_product_settings
    ADD COLUMN IF NOT EXISTS is_available BOOLEAN NOT NULL DEFAULT true,
    ADD COLUMN IF NOT EXISTS custom_price NUMERIC(10, 2) DEFAULT NULL
        CONSTRAINT bps_custom_price_positive CHECK (custom_price IS NULL OR custom_price >= 0);

COMMENT ON COLUMN retail.brand_product_settings.is_available IS 'Whether this product is available for affiliates to promote. Defaults to true. Unavailable products are hidden or de-prioritised.';
COMMENT ON COLUMN retail.brand_product_settings.custom_price IS 'Optional fixed price override displayed to affiliates instead of the Shopify price. NULL = use Shopify price.';

CREATE INDEX IF NOT EXISTS bps_is_available ON retail.brand_product_settings (professional_id, is_available);
