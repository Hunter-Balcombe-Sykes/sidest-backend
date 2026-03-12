-- Normalize professional_type from influencer to ambassador and refresh related contract validations.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'professionals'
    ) THEN
        RAISE EXCEPTION 'core.professionals table does not exist.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'core'
          AND table_name = 'professionals'
          AND column_name = 'professional_type'
    ) THEN
        RAISE EXCEPTION 'core.professionals.professional_type column does not exist.';
    END IF;

    UPDATE core.professionals
    SET professional_type = 'ambassador'
    WHERE lower(regexp_replace(btrim(COALESCE(professional_type, '')), '[^a-z]+', '', 'g')) = 'influencer';

    ALTER TABLE core.professionals
        DROP CONSTRAINT IF EXISTS professionals_professional_type_check;

    ALTER TABLE core.professionals
        ADD CONSTRAINT professionals_professional_type_check
        CHECK (professional_type IN ('barber', 'ambassador', 'hairdresser', 'promoter', 'barbershop', 'salon'));

    COMMENT ON COLUMN core.professionals.professional_type IS
        'Professional business type/category (barber, ambassador, hairdresser, promoter, barbershop, salon)';
END $$;

CREATE OR REPLACE FUNCTION core.validate_influencer_promoter_contract()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    influencer_type text;
    promoter_type   text;
BEGIN
    SELECT p.professional_type
      INTO influencer_type
      FROM core.professionals p
     WHERE p.id = NEW.influencer_professional_id
       AND p.deleted_at IS NULL;

    IF COALESCE(influencer_type, '') NOT IN ('ambassador', 'influencer') THEN
        RAISE EXCEPTION 'influencer_professional_id must reference a professional_type = ambassador'
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT e.enterprise_type
      INTO promoter_type
      FROM core.enterprises e
     WHERE e.id = NEW.promoter_enterprise_id
       AND e.deleted_at IS NULL;

    IF promoter_type IS DISTINCT FROM 'promoter' THEN
        RAISE EXCEPTION 'promoter_enterprise_id must reference an enterprise_type = promoter'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION retail.validate_selection_enterprise_link()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    professional_type text;
    enterprise_type   text;
    has_link          boolean;
BEGIN
    IF NEW.enterprise_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT e.enterprise_type
      INTO enterprise_type
      FROM core.enterprises e
     WHERE e.id = NEW.enterprise_id
       AND e.deleted_at IS NULL;

    IF enterprise_type IS NULL THEN
        RAISE EXCEPTION 'Selected enterprise does not exist or has been deleted.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF enterprise_type <> 'promoter' THEN
        RAISE EXCEPTION 'Product selections can only be linked to promoter enterprises.'
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT p.professional_type
      INTO professional_type
      FROM core.professionals p
     WHERE p.id = NEW.professional_id
       AND p.deleted_at IS NULL;

    IF professional_type IS NULL THEN
        RAISE EXCEPTION 'Professional does not exist or has been deleted.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF professional_type IN ('ambassador', 'influencer') THEN
        SELECT EXISTS (
            SELECT 1
            FROM core.influencer_promoter_contracts c
            WHERE c.influencer_professional_id = NEW.professional_id
              AND c.promoter_enterprise_id = NEW.enterprise_id
              AND c.status = 'active'
              AND c.starts_at <= now()
              AND (c.ends_at IS NULL OR c.ends_at > now())
        )
        INTO has_link;
    ELSE
        SELECT EXISTS (
            SELECT 1
            FROM core.professional_enterprise_memberships m
            WHERE m.professional_id = NEW.professional_id
              AND m.enterprise_id = NEW.enterprise_id
              AND m.starts_at <= now()
              AND (m.ends_at IS NULL OR m.ends_at > now())
        )
        INTO has_link;
    END IF;

    IF NOT has_link THEN
        RAISE EXCEPTION 'Professional is not actively linked to this promoter enterprise.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

COMMIT;
