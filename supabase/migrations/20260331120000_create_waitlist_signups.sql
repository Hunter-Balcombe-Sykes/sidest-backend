BEGIN;

CREATE TABLE IF NOT EXISTS core.waitlist_signups (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    email_lc TEXT NOT NULL,
    phone TEXT NOT NULL,
    applicant_type TEXT NOT NULL,
    applicant_type_other TEXT NULL,
    industry TEXT NOT NULL,
    industry_other TEXT NULL,
    pilot_program_opt_in BOOLEAN NOT NULL DEFAULT FALSE,
    number_of_team_members INTEGER NULL,
    number_of_affiliates_ambassadors INTEGER NULL,
    is_brand_partner_or_ambassador BOOLEAN NULL,
    currently_sells_products BOOLEAN NULL,
    consent_source TEXT NOT NULL DEFAULT 'waitlist_form',
    consent_ip_hash TEXT NULL,
    consent_user_agent TEXT NULL,
    last_submitted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT waitlist_signups_type_check CHECK (
        applicant_type IN ('influencer', 'professional', 'brand', 'other')
    ),

    CONSTRAINT waitlist_signups_industry_check CHECK (
        industry IN (
            'mens_grooming',
            'womens_haircare',
            'beauty_products',
            'vitamins_and_supplements',
            'services_and_software',
            'other'
        )
    ),

    CONSTRAINT waitlist_signups_team_members_non_negative CHECK (
        number_of_team_members IS NULL OR number_of_team_members >= 0
    ),

    CONSTRAINT waitlist_signups_affiliates_non_negative CHECK (
        number_of_affiliates_ambassadors IS NULL OR number_of_affiliates_ambassadors >= 0
    ),

    CONSTRAINT waitlist_signups_type_other_required CHECK (
        (
            applicant_type = 'other'
            AND applicant_type_other IS NOT NULL
            AND BTRIM(applicant_type_other) <> ''
        )
        OR (
            applicant_type <> 'other'
            AND applicant_type_other IS NULL
        )
    ),

    CONSTRAINT waitlist_signups_industry_other_required CHECK (
        (
            industry = 'other'
            AND industry_other IS NOT NULL
            AND BTRIM(industry_other) <> ''
        )
        OR (
            industry <> 'other'
            AND industry_other IS NULL
        )
    ),

    CONSTRAINT waitlist_signups_conditional_fields_check CHECK (
        (
            applicant_type = 'brand'
            AND number_of_team_members IS NOT NULL
            AND number_of_affiliates_ambassadors IS NOT NULL
            AND is_brand_partner_or_ambassador IS NULL
            AND currently_sells_products IS NULL
        )
        OR (
            applicant_type IN ('influencer', 'professional')
            AND number_of_team_members IS NULL
            AND number_of_affiliates_ambassadors IS NULL
            AND is_brand_partner_or_ambassador IS NOT NULL
            AND currently_sells_products IS NOT NULL
        )
        OR (
            applicant_type = 'other'
            AND number_of_team_members IS NULL
            AND number_of_affiliates_ambassadors IS NULL
            AND is_brand_partner_or_ambassador IS NULL
            AND currently_sells_products IS NULL
        )
    )
);

ALTER TABLE core.waitlist_signups OWNER TO postgres;

CREATE UNIQUE INDEX IF NOT EXISTS waitlist_signups_email_lc_unique
    ON core.waitlist_signups (email_lc);

CREATE INDEX IF NOT EXISTS waitlist_signups_last_submitted_idx
    ON core.waitlist_signups (last_submitted_at DESC);

CREATE INDEX IF NOT EXISTS waitlist_signups_type_idx
    ON core.waitlist_signups (applicant_type);

CREATE INDEX IF NOT EXISTS waitlist_signups_industry_idx
    ON core.waitlist_signups (industry);

CREATE INDEX IF NOT EXISTS waitlist_signups_pilot_opt_in_idx
    ON core.waitlist_signups (pilot_program_opt_in);

COMMENT ON TABLE core.waitlist_signups IS
    'Pre-launch waitlist submissions. One canonical row per normalized email.';

COMMENT ON COLUMN core.waitlist_signups.pilot_program_opt_in IS
    'Whether the applicant opted in for pilot-program consideration.';

COMMENT ON COLUMN core.waitlist_signups.last_submitted_at IS
    'Latest submission timestamp for this email (updated on re-submission).';

DROP TRIGGER IF EXISTS trg_waitlist_signups_set_updated_at ON core.waitlist_signups;
CREATE TRIGGER trg_waitlist_signups_set_updated_at
BEFORE UPDATE ON core.waitlist_signups
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA core TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE ON TABLE core.waitlist_signups TO app_backend';
    END IF;
END $$;

COMMIT;
