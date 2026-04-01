-- Add per-brand payout hold period (grace days before affiliate commissions are released).
-- NULL means "use system default" (currently 7 days).
-- The application enforces a minimum of 7 days regardless of what is stored here.

ALTER TABLE retail.brand_store_settings
    ADD COLUMN IF NOT EXISTS payout_hold_days INTEGER DEFAULT NULL;

COMMENT ON COLUMN retail.brand_store_settings.payout_hold_days
    IS 'Brand-specific payout hold period in days. NULL = use system default. Minimum enforced by app is 7 days.';
