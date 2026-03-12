-- Add professional_type classification to core.professionals.
-- Existing records are classified as 'barber'.

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
        ALTER TABLE core.professionals ADD COLUMN professional_type text;
    END IF;

    UPDATE core.professionals
    SET professional_type = 'barber'
    WHERE professional_type IS NULL
       OR btrim(professional_type) = '';

    ALTER TABLE core.professionals
        ALTER COLUMN professional_type SET DEFAULT 'barber',
        ALTER COLUMN professional_type SET NOT NULL;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'professionals_professional_type_check'
          AND connamespace = 'core'::regnamespace
    ) THEN
        ALTER TABLE core.professionals
            ADD CONSTRAINT professionals_professional_type_check
            CHECK (professional_type IN ('barber', 'salon', 'influencer'));
    END IF;

    COMMENT ON COLUMN core.professionals.professional_type IS 'Professional business type/category (barber, salon, influencer)';
END $$;
