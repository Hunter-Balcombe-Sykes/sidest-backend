-- Brand commission funding controls for Stripe payouts.
-- Adds per-brand funding mode + manual top-up balance tracking,
-- plus a top-up transaction table for idempotent reconciliation.

BEGIN;

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_commission_funding_mode TEXT NOT NULL DEFAULT 'auto_charge',
    ADD COLUMN IF NOT EXISTS stripe_manual_balance_cents INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS stripe_manual_balance_currency TEXT NOT NULL DEFAULT 'AUD';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'pro_stripe_commission_funding_mode_check'
    ) THEN
        ALTER TABLE core.professionals
            ADD CONSTRAINT pro_stripe_commission_funding_mode_check
            CHECK (stripe_commission_funding_mode IN ('auto_charge', 'manual_topup'));
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'pro_stripe_manual_balance_non_negative_check'
    ) THEN
        ALTER TABLE core.professionals
            ADD CONSTRAINT pro_stripe_manual_balance_non_negative_check
            CHECK (stripe_manual_balance_cents >= 0);
    END IF;
END $$;

UPDATE core.professionals
SET stripe_commission_funding_mode = 'auto_charge'
WHERE stripe_commission_funding_mode IS NULL;

UPDATE core.professionals
SET stripe_manual_balance_cents = 0
WHERE stripe_manual_balance_cents IS NULL;

UPDATE core.professionals
SET stripe_manual_balance_currency = 'AUD'
WHERE stripe_manual_balance_currency IS NULL OR btrim(stripe_manual_balance_currency) = '';

CREATE TABLE IF NOT EXISTS retail.brand_commission_topups (
    id                         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id      uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    stripe_checkout_session_id TEXT NOT NULL,
    stripe_payment_intent_id   TEXT,
    amount_cents               INTEGER NOT NULL,
    currency_code              TEXT NOT NULL DEFAULT 'AUD',
    status                     TEXT NOT NULL DEFAULT 'completed'
        CONSTRAINT bct_status_check CHECK (status IN ('completed', 'failed')),
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT bct_amount_positive CHECK (amount_cents > 0),
    CONSTRAINT bct_unique_session UNIQUE (stripe_checkout_session_id)
);

ALTER TABLE retail.brand_commission_topups OWNER TO postgres;

CREATE INDEX IF NOT EXISTS idx_bct_brand_created
    ON retail.brand_commission_topups (brand_professional_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_bct_payment_intent
    ON retail.brand_commission_topups (stripe_payment_intent_id)
    WHERE stripe_payment_intent_id IS NOT NULL;

DROP TRIGGER IF EXISTS trg_bct_set_updated_at ON retail.brand_commission_topups;
CREATE TRIGGER trg_bct_set_updated_at
BEFORE UPDATE ON retail.brand_commission_topups
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

COMMENT ON TABLE retail.brand_commission_topups IS
    'Manual commission funding top-ups made by brands; used to maintain per-brand payout wallet balances.';

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.brand_commission_topups TO app_backend';
    END IF;
END $$;

COMMIT;
