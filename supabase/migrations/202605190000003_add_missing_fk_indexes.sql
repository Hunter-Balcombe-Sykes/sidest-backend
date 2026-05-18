-- Master Pattern 24: Add indexes on FK columns missing them.
-- CREATE INDEX CONCURRENTLY cannot run inside a transaction.
-- No BEGIN/COMMIT here — this file must run outside any explicit transaction block.

-- Partial: most sites have a theme, so the partial form keeps the index compact.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sites_theme_id
    ON site.sites (theme_id) WHERE theme_id IS NOT NULL;

-- Full: brand_professional_id is NOT NULL on this table, so no partial needed.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_aps_brand_professional_id
    ON commerce.affiliate_product_selections (brand_professional_id);

-- Partial: topup_id is nullable (soft FK); only index rows that have a topup.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wcsa_topup_id
    ON core.wallet_currency_switch_audit (topup_id) WHERE topup_id IS NOT NULL;
