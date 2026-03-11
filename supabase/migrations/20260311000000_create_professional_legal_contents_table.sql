-- Add per-professional legal content storage with generated/manual variants + active source toggles.

BEGIN;

CREATE TABLE IF NOT EXISTS core.professional_legal_contents (
    professional_id uuid PRIMARY KEY REFERENCES core.professionals(id) ON DELETE CASCADE,
    generated_privacy_policy text NOT NULL DEFAULT '',
    manual_privacy_policy text NULL,
    active_privacy_source varchar(16) COLLATE "C" NOT NULL DEFAULT 'templated',
    generated_terms_and_conditions text NOT NULL DEFAULT '',
    manual_terms_and_conditions text NULL,
    active_terms_source varchar(16) COLLATE "C" NOT NULL DEFAULT 'templated',
    template_variables jsonb NOT NULL DEFAULT '{}'::jsonb,
    generated_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT professional_legal_contents_active_privacy_source_chk
        CHECK (active_privacy_source IN ('templated', 'manual')),
    CONSTRAINT professional_legal_contents_active_terms_source_chk
        CHECK (active_terms_source IN ('templated', 'manual'))
);

CREATE INDEX IF NOT EXISTS professional_legal_contents_generated_at_idx
    ON core.professional_legal_contents (generated_at);

DROP TRIGGER IF EXISTS set_timestamp_professional_legal_contents ON core.professional_legal_contents;
CREATE TRIGGER set_timestamp_professional_legal_contents
BEFORE UPDATE ON core.professional_legal_contents
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

ALTER TABLE core.professional_legal_contents ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS legal_contents_pro_all ON core.professional_legal_contents;
CREATE POLICY legal_contents_pro_all ON core.professional_legal_contents
    TO authenticated
    USING (professional_id = (
        SELECT id FROM core.professionals
        WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ))
    WITH CHECK (professional_id = (
        SELECT id FROM core.professionals
        WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

DROP POLICY IF EXISTS legal_contents_staff_all ON core.professional_legal_contents;
CREATE POLICY legal_contents_staff_all ON core.professional_legal_contents
    TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.comet_staff cs
        WHERE cs.auth_user_id = auth.uid()
    ))
    WITH CHECK (EXISTS (
        SELECT 1 FROM core.comet_staff cs
        WHERE cs.auth_user_id = auth.uid()
    ));

GRANT SELECT, INSERT, UPDATE, DELETE ON core.professional_legal_contents TO authenticated, service_role;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON core.professional_legal_contents TO app_backend';
    END IF;
END $$;

-- Pre-seed rows so existing professionals can toggle legal sources immediately.
INSERT INTO core.professional_legal_contents (professional_id)
SELECT p.id
FROM core.professionals p
ON CONFLICT (professional_id) DO NOTHING;

COMMIT;
