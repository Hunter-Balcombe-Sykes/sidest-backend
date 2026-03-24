-- Stripe Connect integration for commission payouts.
-- Adds Stripe Connect fields to professionals and creates the payout ledger
-- for tracking commission transfers from brands to affiliates.

BEGIN;

-- ============================================================
-- 1) Stripe Connect columns on professionals
--    - stripe_connect_account_id: Express account for receiving payouts (affiliates)
--    - stripe_connect_status: onboarding lifecycle
--    - stripe_customer_id: Customer object for charging commissions (brands)
--    - stripe_payment_method_id: default payment method for brand charges
-- ============================================================
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_connect_account_id TEXT,
    ADD COLUMN IF NOT EXISTS stripe_connect_status TEXT NOT NULL DEFAULT 'not_connected'
        CONSTRAINT pro_stripe_connect_status_check CHECK (stripe_connect_status IN ('not_connected', 'onboarding', 'active', 'restricted')),
    ADD COLUMN IF NOT EXISTS stripe_customer_id TEXT,
    ADD COLUMN IF NOT EXISTS stripe_payment_method_id TEXT;

CREATE INDEX IF NOT EXISTS idx_professionals_stripe_connect_account
    ON core.professionals (stripe_connect_account_id)
    WHERE stripe_connect_account_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_professionals_stripe_customer
    ON core.professionals (stripe_customer_id)
    WHERE stripe_customer_id IS NOT NULL;

-- ============================================================
-- 2) retail.commission_payouts
--    Each row represents a batched payout from one brand to one
--    affiliate for a given currency. Groups all eligible ledger
--    entries that have passed the hold period.
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.commission_payouts (
    id                        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,

    -- Stripe objects
    stripe_payment_intent_id  TEXT,
    stripe_transfer_id        TEXT,

    -- Status tracking
    status                    TEXT NOT NULL DEFAULT 'pending'
        CONSTRAINT cp_status_check CHECK (status IN ('pending', 'collecting', 'collected', 'transferring', 'completed', 'failed', 'cancelled')),

    -- Money
    gross_commission_cents    INTEGER NOT NULL,
    platform_fee_cents        INTEGER NOT NULL DEFAULT 0,
    net_payout_cents          INTEGER NOT NULL,
    currency_code             TEXT NOT NULL DEFAULT 'AUD',

    -- Metadata
    failure_reason            TEXT,
    failure_code              TEXT,
    ledger_entry_count        INTEGER NOT NULL DEFAULT 0,

    -- Scheduling
    eligible_after            TIMESTAMPTZ NOT NULL,
    processed_at              TIMESTAMPTZ,

    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT cp_different_parties CHECK (brand_professional_id <> affiliate_professional_id),
    CONSTRAINT cp_amounts_positive CHECK (gross_commission_cents > 0 AND net_payout_cents >= 0 AND platform_fee_cents >= 0)
);

ALTER TABLE retail.commission_payouts OWNER TO postgres;

COMMENT ON TABLE retail.commission_payouts IS
    'Batched commission payout from a brand to an affiliate. One row per (brand, affiliate, currency) batch.';

CREATE INDEX IF NOT EXISTS idx_cp_brand ON retail.commission_payouts (brand_professional_id);
CREATE INDEX IF NOT EXISTS idx_cp_affiliate ON retail.commission_payouts (affiliate_professional_id);
CREATE INDEX IF NOT EXISTS idx_cp_status_eligible ON retail.commission_payouts (status, eligible_after)
    WHERE status = 'pending';

DROP TRIGGER IF EXISTS trg_cp_set_updated_at ON retail.commission_payouts;
CREATE TRIGGER trg_cp_set_updated_at
BEFORE UPDATE ON retail.commission_payouts
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 3) retail.commission_payout_items
--    Links individual commission_ledger_entries to the payout
--    batch they were paid out in.
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.commission_payout_items (
    id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    payout_id                   uuid NOT NULL REFERENCES retail.commission_payouts(id) ON DELETE CASCADE,
    commission_ledger_entry_id  uuid NOT NULL REFERENCES retail.commission_ledger_entries(id) ON DELETE RESTRICT,
    amount_cents                INTEGER NOT NULL,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT cpi_unique_entry UNIQUE (commission_ledger_entry_id)
);

ALTER TABLE retail.commission_payout_items OWNER TO postgres;

COMMENT ON TABLE retail.commission_payout_items IS
    'Links individual commission ledger entries to the payout batch they were settled in. Each entry can only be paid out once.';

CREATE INDEX IF NOT EXISTS idx_cpi_payout ON retail.commission_payout_items (payout_id);

-- ============================================================
-- 4) Add payout_id column to commission_ledger_entries for
--    quick lookup of paid vs unpaid entries.
-- ============================================================
ALTER TABLE retail.commission_ledger_entries
    ADD COLUMN IF NOT EXISTS payout_id uuid REFERENCES retail.commission_payouts(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_cle_unpaid ON retail.commission_ledger_entries (brand_professional_id, affiliate_professional_id, currency_code)
    WHERE payout_id IS NULL AND entry_type = 'accrual' AND status = 'approved';

-- ============================================================
-- 5) Grants for runtime role
-- ============================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.commission_payouts TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.commission_payout_items TO app_backend';
    END IF;
END $$;

COMMIT;
