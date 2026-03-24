-- Brand team memberships for unified brand RBAC.

BEGIN;

CREATE TABLE IF NOT EXISTS retail.brand_team_memberships (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    member_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    role text NOT NULL DEFAULT 'read_only',
    status text NOT NULL DEFAULT 'active',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_team_memberships_role_check
        CHECK (role IN ('owner', 'finance', 'marketing', 'analyst', 'read_only')),
    CONSTRAINT brand_team_memberships_status_check
        CHECK (status IN ('active', 'inactive'))
);

ALTER TABLE retail.brand_team_memberships OWNER TO postgres;

COMMENT ON TABLE retail.brand_team_memberships IS
    'Brand team membership role assignments used by brand analytics and store RBAC.';

CREATE INDEX IF NOT EXISTS btm_brand_status_role_idx
    ON retail.brand_team_memberships (brand_professional_id, status, role);

CREATE INDEX IF NOT EXISTS btm_member_status_idx
    ON retail.brand_team_memberships (member_professional_id, status);

CREATE UNIQUE INDEX IF NOT EXISTS btm_active_brand_member_uq
    ON retail.brand_team_memberships (brand_professional_id, member_professional_id)
    WHERE status = 'active';

CREATE UNIQUE INDEX IF NOT EXISTS btm_single_active_owner_uq
    ON retail.brand_team_memberships (brand_professional_id)
    WHERE status = 'active' AND role = 'owner';

DROP TRIGGER IF EXISTS trg_brand_team_memberships_set_updated_at ON retail.brand_team_memberships;
CREATE TRIGGER trg_brand_team_memberships_set_updated_at
BEFORE UPDATE ON retail.brand_team_memberships
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE FUNCTION core.validate_brand_team_membership()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    brand_type text;
BEGIN
    SELECT p.professional_type
      INTO brand_type
      FROM core.professionals p
     WHERE p.id = NEW.brand_professional_id
       AND p.deleted_at IS NULL;

    IF brand_type IS DISTINCT FROM 'brand' THEN
        RAISE EXCEPTION 'brand_team_memberships.brand_professional_id must reference professional_type = brand'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_brand_team_membership ON retail.brand_team_memberships;
CREATE TRIGGER trg_validate_brand_team_membership
BEFORE INSERT OR UPDATE OF brand_professional_id
ON retail.brand_team_memberships
FOR EACH ROW
EXECUTE FUNCTION core.validate_brand_team_membership();

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA retail TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.brand_team_memberships TO app_backend';
    END IF;
END $$;

COMMIT;
