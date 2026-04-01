BEGIN;

ALTER TABLE core.brand_profiles
    ADD COLUMN IF NOT EXISTS brand_status TEXT NOT NULL DEFAULT 'deactivated';

ALTER TABLE core.brand_profiles
    ADD CONSTRAINT chk_brand_profiles_brand_status
        CHECK (brand_status IN ('active', 'deactivated'));

-- Existing brands are considered active; only new brands start deactivated.
UPDATE core.brand_profiles SET brand_status = 'active';

COMMENT ON COLUMN core.brand_profiles.brand_status
    IS 'Operational status of the brand affiliate program. deactivated = no new connections, no product sales. Synced automatically from onboarding readiness.';

COMMIT;
