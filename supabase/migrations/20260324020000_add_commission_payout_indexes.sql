-- Additional indexes for commission payout queries at scale.

BEGIN;

-- Index for looking up which ledger entries belong to a specific payout.
CREATE INDEX IF NOT EXISTS idx_cle_payout
    ON retail.commission_ledger_entries (payout_id)
    WHERE payout_id IS NOT NULL;

-- Composite index for the GROUP BY aggregation query used by the daily
-- payout job: (brand, affiliate, currency) filtered by unpaid reversals.
CREATE INDEX IF NOT EXISTS idx_cle_unpaid_reversals
    ON retail.commission_ledger_entries (brand_professional_id, affiliate_professional_id, currency_code)
    WHERE payout_id IS NULL AND entry_type = 'reversal' AND status = 'approved';

-- Index for claiming pending payouts ordered by eligibility.
CREATE INDEX IF NOT EXISTS idx_cp_pending_eligible
    ON retail.commission_payouts (eligible_after)
    WHERE status = 'pending' AND processed_at IS NULL;

COMMIT;
