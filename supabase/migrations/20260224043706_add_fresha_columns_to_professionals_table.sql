-- Add Fresha OAuth tokens and sync fields to professionals table (idempotent)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'fresha_access_token') THEN
        ALTER TABLE core.professionals ADD COLUMN fresha_access_token text COLLATE "C" NULL;
        COMMENT ON COLUMN core.professionals.fresha_access_token IS 'Encrypted Fresha OAuth access token';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'fresha_refresh_token') THEN
        ALTER TABLE core.professionals ADD COLUMN fresha_refresh_token text COLLATE "C" NULL;
        COMMENT ON COLUMN core.professionals.fresha_refresh_token IS 'Encrypted Fresha OAuth refresh token';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'fresha_business_id') THEN
        ALTER TABLE core.professionals ADD COLUMN fresha_business_id varchar(255) COLLATE "C" NULL;
        COMMENT ON COLUMN core.professionals.fresha_business_id IS 'Fresha business ID';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'fresha_expires_at') THEN
        ALTER TABLE core.professionals ADD COLUMN fresha_expires_at timestamp with time zone NULL;
        COMMENT ON COLUMN core.professionals.fresha_expires_at IS 'Timestamp when Fresha access token expires';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'fresha_catalog_latest_time') THEN
        ALTER TABLE core.professionals ADD COLUMN fresha_catalog_latest_time timestamp with time zone NULL;
        COMMENT ON COLUMN core.professionals.fresha_catalog_latest_time IS 'Latest catalog version timestamp from Fresha API';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'fresha_last_catalog_sync_at') THEN
        ALTER TABLE core.professionals ADD COLUMN fresha_last_catalog_sync_at timestamp with time zone NULL;
        COMMENT ON COLUMN core.professionals.fresha_last_catalog_sync_at IS 'When we last synced catalog from Fresha';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'fresha_last_catalog_sync_error') THEN
        ALTER TABLE core.professionals ADD COLUMN fresha_last_catalog_sync_error text COLLATE "C" NULL;
        COMMENT ON COLUMN core.professionals.fresha_last_catalog_sync_error IS 'Last error encountered during Fresha catalog sync';
    END IF;
END $$;