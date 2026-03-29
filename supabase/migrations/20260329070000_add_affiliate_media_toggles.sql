BEGIN;

-- -------------------------------------------------------------------------
-- Overall brand toggle: brand can globally disable affiliate product photos
-- -------------------------------------------------------------------------
ALTER TABLE retail.brand_store_settings
    ADD COLUMN IF NOT EXISTS allow_affiliate_media BOOLEAN NOT NULL DEFAULT TRUE;

COMMENT ON COLUMN retail.brand_store_settings.allow_affiliate_media
    IS 'When false, affiliates cannot upload custom product photos for any of this brand''s products.';

-- -------------------------------------------------------------------------
-- Per-product toggle: brand can disable affiliate photos on a specific product
-- -------------------------------------------------------------------------
ALTER TABLE retail.brand_product_settings
    ADD COLUMN IF NOT EXISTS allow_affiliate_media BOOLEAN NOT NULL DEFAULT TRUE;

COMMENT ON COLUMN retail.brand_product_settings.allow_affiliate_media
    IS 'When false, affiliates cannot upload custom photos for this specific product.';

-- -------------------------------------------------------------------------
-- Per-affiliate toggle: brand can disable affiliate photos for a specific affiliate
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retail.brand_affiliate_settings (
    id                       UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id    UUID        NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id UUID       NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    allow_affiliate_media    BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT brand_affiliate_settings_not_self
        CHECK (brand_professional_id <> affiliate_professional_id),

    UNIQUE (brand_professional_id, affiliate_professional_id)
);

COMMENT ON TABLE retail.brand_affiliate_settings
    IS 'Brand-managed per-affiliate settings (e.g. whether the affiliate can upload product media).';

CREATE INDEX IF NOT EXISTS brand_affiliate_settings_brand_idx
    ON retail.brand_affiliate_settings (brand_professional_id);

CREATE INDEX IF NOT EXISTS brand_affiliate_settings_affiliate_idx
    ON retail.brand_affiliate_settings (affiliate_professional_id);

COMMIT;
