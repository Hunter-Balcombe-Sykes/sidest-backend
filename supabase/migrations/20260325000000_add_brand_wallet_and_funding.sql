-- Brand commission wallet: manual top-ups, funding columns, and topups ledger.
-- Brands must add a card (required). They can optionally top up a wallet balance
-- which is used first; the card covers any shortfall.

BEGIN;

-- ============================================================
-- 1) Add wallet / funding columns to professionals
-- ============================================================
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_commission_funding_mode TEXT NOT NULL DEFAULT 'auto_charge',
    ADD COLUMN IF NOT EXISTS stripe_manual_balance_cents INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS stripe_manual_balance_currency TEXT NOT NULL DEFAULT 'AUD';

-- ============================================================
-- 2) Add funding tracking columns to commission_payouts
-- ============================================================
ALTER TABLE retail.commission_payouts
    ADD COLUMN IF NOT EXISTS funding_source TEXT,
    ADD COLUMN IF NOT EXISTS wallet_debit_cents INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS charge_cents INTEGER NOT NULL DEFAULT 0;

-- Expand status enum to include 'pending_funds'
ALTER TABLE retail.commission_payouts
    DROP CONSTRAINT IF EXISTS cp_status_check;
ALTER TABLE retail.commission_payouts
    ADD CONSTRAINT cp_status_check CHECK (
        status IN ('pending', 'pending_funds', 'collecting', 'collected',
                   'transferring', 'completed', 'failed', 'cancelled')
    );

-- ============================================================
-- 3) Brand commission top-ups ledger
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.brand_commission_topups (
    id                          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id       UUID NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    stripe_checkout_session_id  TEXT NOT NULL,
    stripe_payment_intent_id    TEXT,
    amount_cents                INTEGER NOT NULL,
    currency_code               TEXT NOT NULL DEFAULT 'AUD',
    status                      TEXT NOT NULL DEFAULT 'pending'
        CONSTRAINT bct_status_check CHECK (status IN ('pending', 'completed', 'failed')),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT bct_amount_positive CHECK (amount_cents > 0),
    CONSTRAINT bct_unique_session UNIQUE (stripe_checkout_session_id)
);

ALTER TABLE retail.brand_commission_topups OWNER TO postgres;

CREATE INDEX IF NOT EXISTS idx_bct_brand
    ON retail.brand_commission_topups (brand_professional_id);

DROP TRIGGER IF EXISTS trg_bct_set_updated_at ON retail.brand_commission_topups;
CREATE TRIGGER trg_bct_set_updated_at
BEFORE UPDATE ON retail.brand_commission_topups
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 4) Grants
-- ============================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.brand_commission_topups TO app_backend';
    END IF;
END $$;

COMMIT;
