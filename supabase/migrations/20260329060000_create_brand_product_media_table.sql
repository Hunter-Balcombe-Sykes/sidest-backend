BEGIN;

-- Per-affiliate custom product media uploads.
-- Affiliates can upload their own photos for products they promote
-- (e.g., wearing or using the product) to supplement Shopify images.

CREATE TABLE IF NOT EXISTS retail.brand_product_media (
    id               UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_product_id UUID        NOT NULL REFERENCES retail.brand_products(id) ON DELETE CASCADE,
    professional_id  UUID        NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_media_id    UUID        NOT NULL REFERENCES core.site_media(id) ON DELETE CASCADE,
    sort_order       INTEGER     NOT NULL DEFAULT 0,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (brand_product_id, professional_id, site_media_id)
);

-- Lookup index: fetch all media for a given product + affiliate (ordered for display).
CREATE INDEX IF NOT EXISTS brand_product_media_product_pro_sort_idx
    ON retail.brand_product_media (brand_product_id, professional_id, sort_order);

-- Lookup index: find all product media items owned by a professional (for cleanup on account deletion).
CREATE INDEX IF NOT EXISTS brand_product_media_professional_idx
    ON retail.brand_product_media (professional_id);

COMMIT;
