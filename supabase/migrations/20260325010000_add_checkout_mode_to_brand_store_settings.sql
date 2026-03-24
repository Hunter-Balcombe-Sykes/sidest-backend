BEGIN;

ALTER TABLE retail.brand_store_settings
    ADD COLUMN IF NOT EXISTS checkout_mode TEXT NOT NULL DEFAULT 'shopify';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bss_checkout_mode_check'
    ) THEN
        ALTER TABLE retail.brand_store_settings
            ADD CONSTRAINT bss_checkout_mode_check
            CHECK (checkout_mode IN ('shopify', 'stripe'));
    END IF;
END $$;

UPDATE retail.brand_store_settings
SET checkout_mode = 'shopify'
WHERE checkout_mode IS NULL OR btrim(checkout_mode) = '';

COMMENT ON COLUMN retail.brand_store_settings.checkout_mode IS
    'Controls storefront checkout handling for this brand. shopify = Shopify-hosted checkout, stripe = Comet Stripe checkout with Shopify order sync.';

COMMIT;
