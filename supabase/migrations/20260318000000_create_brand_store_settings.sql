-- Brand store management tables
-- brand_store_settings: per-brand default commission rate
-- brand_product_settings: per-product commission override, discount rate, featured flag

CREATE TABLE IF NOT EXISTS retail.brand_store_settings (
    id                     UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id        UUID         NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    default_commission_rate NUMERIC(5,2) NOT NULL DEFAULT 15
        CONSTRAINT bss_commission_range CHECK (default_commission_rate >= 0 AND default_commission_rate <= 100),
    created_at             TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (professional_id)
);

COMMENT ON TABLE retail.brand_store_settings IS 'Brand-level store config: default commission rate applied to all affiliates unless overridden per product.';

CREATE TABLE IF NOT EXISTS retail.brand_product_settings (
    id                  UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id     UUID         NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    shopify_product_id  TEXT         NOT NULL,
    commission_override NUMERIC(5,2) DEFAULT NULL
        CONSTRAINT bps_commission_range CHECK (commission_override IS NULL OR (commission_override >= 0 AND commission_override <= 100)),
    discount_rate       NUMERIC(5,2) DEFAULT NULL
        CONSTRAINT bps_discount_range CHECK (discount_rate IS NULL OR (discount_rate >= 0 AND discount_rate <= 100)),
    is_featured         BOOLEAN      NOT NULL DEFAULT false,
    sort_order          INTEGER      NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (professional_id, shopify_product_id)
);

COMMENT ON TABLE retail.brand_product_settings IS 'Per-product settings for brand accounts: custom commission rate, discount %, and featured flag.';
COMMENT ON COLUMN retail.brand_product_settings.commission_override IS 'Per-product commission rate (0-100%). Must be >= brand default_commission_rate. NULL = use default.';
COMMENT ON COLUMN retail.brand_product_settings.discount_rate IS 'Discount applied to this product price for affiliates (0-100%). NULL = no discount.';
COMMENT ON COLUMN retail.brand_product_settings.is_featured IS 'Whether this product is default-featured for new affiliates (max 10 per brand).';

CREATE INDEX IF NOT EXISTS bps_professional_id    ON retail.brand_product_settings (professional_id);
CREATE INDEX IF NOT EXISTS bps_professional_featured ON retail.brand_product_settings (professional_id, is_featured);

-- Grant access to app_backend runtime role
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    IF EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'retail') THEN
      EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON retail.brand_store_settings TO app_backend';
      EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON retail.brand_product_settings TO app_backend';
      EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA retail TO app_backend';
    END IF;
  END IF;
END $$;
