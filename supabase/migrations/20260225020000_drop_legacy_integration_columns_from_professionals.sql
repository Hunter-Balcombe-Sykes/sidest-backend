-- Remove legacy provider-specific integration columns from core.professionals.
-- Integration data now lives in core.professional_integrations.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'professional_integrations'
    ) THEN
        RAISE EXCEPTION 'core.professional_integrations does not exist; run 20260225010000_create_professional_integrations_table.sql first.';
    END IF;
END $$;

ALTER TABLE core.professionals
    DROP COLUMN IF EXISTS square_access_token,
    DROP COLUMN IF EXISTS square_refresh_token,
    DROP COLUMN IF EXISTS square_merchant_id,
    DROP COLUMN IF EXISTS square_expires_at,
    DROP COLUMN IF EXISTS square_catalog_latest_time,
    DROP COLUMN IF EXISTS square_last_catalog_sync_at,
    DROP COLUMN IF EXISTS square_last_catalog_sync_error,
    DROP COLUMN IF EXISTS fresha_access_token,
    DROP COLUMN IF EXISTS fresha_refresh_token,
    DROP COLUMN IF EXISTS fresha_business_id,
    DROP COLUMN IF EXISTS fresha_expires_at,
    DROP COLUMN IF EXISTS fresha_catalog_latest_time,
    DROP COLUMN IF EXISTS fresha_last_catalog_sync_at,
    DROP COLUMN IF EXISTS fresha_last_catalog_sync_error;
