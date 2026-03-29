-- Add missing indexes identified in bottleneck analysis.

-- ---------------------------------------------------------------------------
-- retail.orders – composite indexes for brand/affiliate payout period queries
-- ---------------------------------------------------------------------------
-- Existing: orders_brand_ordered_idx (brand_professional_id, ordered_at DESC)
--           orders_financial_status_idx (financial_status, ordered_at DESC)
-- Missing: brand-scoped + status filter (e.g. "paid orders for brand in period")
CREATE INDEX IF NOT EXISTS orders_brand_financial_status_idx
    ON retail.orders (brand_professional_id, financial_status, ordered_at DESC);

-- Missing: affiliate-scoped + status filter (e.g. "paid orders for affiliate earnings")
CREATE INDEX IF NOT EXISTS orders_affiliate_financial_status_idx
    ON retail.orders (affiliate_professional_id, financial_status, ordered_at DESC);

-- ---------------------------------------------------------------------------
-- retail.checkout_sessions – expiry sweep index
-- ---------------------------------------------------------------------------
-- Cleanup jobs that sweep expired/stale sessions have no index to satisfy
-- WHERE status IN ('active', 'expired') ORDER BY expires_at.
CREATE INDEX IF NOT EXISTS checkout_sessions_expires_status_idx
    ON retail.checkout_sessions (expires_at, status)
    WHERE status IN ('active', 'expired');

-----------------------------------------------------------------------------
-- core.blocks – block_type partial index for site rendering
-- ---------------------------------------------------------------------------
-- Site rendering queries filter by block_type (e.g. 'link', 'shop', 'gallery')
-- but the existing active index only covers (site_id, block_group, sort_order).
CREATE INDEX IF NOT EXISTS blocks_site_type_active_idx
    ON core.blocks (site_id, block_type, sort_order)
    WHERE deleted_at IS NULL AND is_active = true;

-- ---------------------------------------------------------------------------
-- billing.subscriptions – batch operation indexes
-- ---------------------------------------------------------------------------
-- Trial expiry sweep: find trialing/active subs nearing trial end
CREATE INDEX IF NOT EXISTS billing_subscriptions_trial_ends_idx
    ON billing.subscriptions (trial_ends_at)
    WHERE status IN ('trialing', 'active') AND trial_ends_at IS NOT NULL;

-- Cancel-at-period-end processing: find subs scheduled for cancellation
CREATE INDEX IF NOT EXISTS billing_subscriptions_cancel_period_end_idx
    ON billing.subscriptions (current_period_end)
    WHERE cancel_at_period_end = true AND ended_at IS NULL;

-- Plan distribution / reporting: which plans have active subscribers
CREATE INDEX IF NOT EXISTS billing_subscriptions_plan_status_idx
    ON billing.subscriptions (plan_id, status)
    WHERE ended_at IS NULL;

-- ---------------------------------------------------------------------------
-- core.site_media – covering index (eliminates heap fetches for gallery rendering)
-- ---------------------------------------------------------------------------
-- The public_site_payload view reads site_id, sort_order, alt_text, bucket, path,
-- media_type for every gallery item. A covering index avoids hitting the heap.
-- Drop the old non-covering partial index first (safe: replaced below).
DROP INDEX IF EXISTS core.site_images_site_active_sort_idx;

CREATE INDEX IF NOT EXISTS site_media_site_active_sort_covering_idx
    ON core.site_media (site_id, sort_order)
    INCLUDE (bucket, path, alt_text, media_type)
    WHERE deleted_at IS NULL AND is_active = true;
