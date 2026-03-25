BEGIN;

CREATE TABLE IF NOT EXISTS core.brand_profiles (
    id UUID DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
    professional_id UUID NOT NULL UNIQUE REFERENCES core.professionals(id) ON DELETE CASCADE,
    abn TEXT,
    acn TEXT,
    legal_business_name TEXT,
    business_type TEXT,
    industries JSONB DEFAULT '[]'::jsonb NOT NULL,
    estimated_annual_income TEXT,
    business_website TEXT,
    created_at TIMESTAMPTZ DEFAULT now() NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT now() NOT NULL
);

CREATE INDEX idx_brand_profiles_professional_id ON core.brand_profiles(professional_id);

COMMENT ON TABLE core.brand_profiles IS 'Brand-specific business fields (ABN, ACN, legal name, etc.)';
COMMENT ON COLUMN core.brand_profiles.abn IS 'Australian Business Number';
COMMENT ON COLUMN core.brand_profiles.acn IS 'Australian Company Number';
COMMENT ON COLUMN core.brand_profiles.industries IS 'JSON array of industry strings';

COMMIT;
