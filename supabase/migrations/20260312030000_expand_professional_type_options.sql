-- Expand professional_type options to support unified enterprise-first onboarding.
-- Allowed values become: barber, ambassador, hairdresser, promoter, barbershop, salon.

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

    ALTER TABLE core.professionals
        DROP CONSTRAINT IF EXISTS professionals_professional_type_check;

    UPDATE core.professionals
    SET professional_type = lower(regexp_replace(btrim(professional_type), '[^a-z]+', '', 'g'))
    WHERE professional_type IS NOT NULL;

    UPDATE core.professionals
    SET professional_type = 'hairdresser'
    WHERE professional_type IN ('hairdresser', 'hairstylist');

    UPDATE core.professionals
    SET professional_type = 'ambassador'
    WHERE professional_type IN ('ambassador', 'influencer');

    UPDATE core.professionals
    SET professional_type = 'salon'
    WHERE professional_type IN ('salon', 'hairsalon');

    UPDATE core.professionals
    SET professional_type = 'barber'
    WHERE professional_type IS NULL
       OR professional_type = '';

    UPDATE core.professionals
    SET professional_type = 'barber'
    WHERE professional_type NOT IN ('barber', 'ambassador', 'hairdresser', 'promoter', 'barbershop', 'salon');

    ALTER TABLE core.professionals
        ALTER COLUMN professional_type SET DEFAULT 'barber',
        ALTER COLUMN professional_type SET NOT NULL;

    ALTER TABLE core.professionals
        ADD CONSTRAINT professionals_professional_type_check
        CHECK (professional_type IN ('barber', 'ambassador', 'hairdresser', 'promoter', 'barbershop', 'salon'));

    COMMENT ON COLUMN core.professionals.professional_type IS
        'Professional business type/category (barber, ambassador, hairdresser, promoter, barbershop, salon)';
END $$;
