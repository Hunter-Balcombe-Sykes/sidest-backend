BEGIN;

ALTER TABLE core.brand_profiles
    ADD COLUMN IF NOT EXISTS affiliate_visibility TEXT NOT NULL DEFAULT 'invite_only';

ALTER TABLE core.brand_profiles
    ADD CONSTRAINT chk_brand_profiles_affiliate_visibility
        CHECK (affiliate_visibility IN ('public', 'invite_only'));

COMMENT ON COLUMN core.brand_profiles.affiliate_visibility
    IS 'Controls whether affiliates can discover and connect to this brand freely (public) or only via invitation (invite_only).';

COMMIT;
