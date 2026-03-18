-- Align professional_type constraint with application-supported values.

BEGIN;

ALTER TABLE core.professionals
    DROP CONSTRAINT IF EXISTS professionals_professional_type_check;

UPDATE core.professionals
SET professional_type = lower(regexp_replace(btrim(COALESCE(professional_type, '')), '[^a-z]+', '', 'g'))
WHERE professional_type IS NOT NULL;

UPDATE core.professionals
SET professional_type = 'influencer'
WHERE professional_type = 'creator';

UPDATE core.professionals
SET professional_type = 'hairdresser'
WHERE professional_type = 'hairstylist';

UPDATE core.professionals
SET professional_type = 'barber'
WHERE professional_type IS NULL
   OR professional_type = ''
   OR professional_type NOT IN (
        'professional',
        'influencer',
        'barber',
        'hairdresser',
        'ambassador',
        'promoter',
        'brand',
        'barbershop',
        'salon'
    );

ALTER TABLE core.professionals
    ADD CONSTRAINT professionals_professional_type_check
    CHECK (
        professional_type IN (
            'professional',
            'influencer',
            'barber',
            'hairdresser',
            'ambassador',
            'promoter',
            'brand',
            'barbershop',
            'salon'
        )
    );

COMMENT ON COLUMN core.professionals.professional_type IS
    'Professional business type/category (professional, influencer, barber, hairdresser, ambassador, promoter, brand, barbershop, salon)';

COMMIT;
