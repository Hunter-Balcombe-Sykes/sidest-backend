ALTER TABLE retail.brand_products
    ADD COLUMN IF NOT EXISTS description text,
    ADD COLUMN IF NOT EXISTS product_type text,
    ADD COLUMN IF NOT EXISTS tags text[] NOT NULL DEFAULT '{}',
    ADD COLUMN IF NOT EXISTS images jsonb NOT NULL DEFAULT '[]'::jsonb;

COMMENT ON COLUMN retail.brand_products.description IS 'Plain-text product description from Shopify.';
COMMENT ON COLUMN retail.brand_products.product_type IS 'Shopify product type classification.';
COMMENT ON COLUMN retail.brand_products.tags IS 'Array of Shopify product tags.';
COMMENT ON COLUMN retail.brand_products.images IS 'JSON array of {url, altText} objects from Shopify images.';
