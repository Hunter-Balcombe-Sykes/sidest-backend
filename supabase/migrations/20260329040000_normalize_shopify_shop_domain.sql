-- Normalize shop_domain out of JSONB into a generated stored column.
--
-- Problem: the existing expression index on lower(provider_metadata->>'shop_domain')
-- is fragile — if the JSONB key is ever renamed or restructured the index silently
-- stops being used (or stops being maintained). Application code must also repeat
-- the raw JSONB expression in every WHERE clause.
--
-- Fix: add a GENERATED ALWAYS AS STORED column that extracts the value once at
-- write time, then rebuild the unique index on the plain column. Application code
-- can use the simple column name instead of the expression.

ALTER TABLE core.professional_integrations
    ADD COLUMN IF NOT EXISTS shopify_shop_domain text
    GENERATED ALWAYS AS (
        CASE
            WHEN provider = 'shopify'
            THEN lower(trim(provider_metadata ->> 'shop_domain'))
            ELSE NULL
        END
    ) STORED;

-- Build the new unique index on the generated column.
CREATE UNIQUE INDEX IF NOT EXISTS professional_integrations_shopify_domain_uq
    ON core.professional_integrations (shopify_shop_domain)
    WHERE shopify_shop_domain IS NOT NULL;

-- Drop the old expression index now that the generated column index covers it.
DROP INDEX IF EXISTS core.professional_integrations_shopify_shop_domain_uq;
