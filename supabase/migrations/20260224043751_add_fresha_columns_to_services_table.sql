-- Add Fresha sync fields to services table (idempotent)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'fresha_service_id') THEN
        ALTER TABLE core.services ADD COLUMN fresha_service_id varchar(255) COLLATE "C" NULL;
        COMMENT ON COLUMN core.services.fresha_service_id IS 'Fresha service ID';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'fresha_variation_id') THEN
        ALTER TABLE core.services ADD COLUMN fresha_variation_id varchar(255) COLLATE "C" NULL;
        COMMENT ON COLUMN core.services.fresha_variation_id IS 'Fresha variation ID for this service';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'fresha_service_version') THEN
        ALTER TABLE core.services ADD COLUMN fresha_service_version bigint NULL;
        COMMENT ON COLUMN core.services.fresha_service_version IS 'Version from Fresha service';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'fresha_last_synced_at') THEN
        ALTER TABLE core.services ADD COLUMN fresha_last_synced_at timestamp with time zone COLLATE "C" NULL;
        COMMENT ON COLUMN core.services.fresha_last_synced_at IS 'When this service was last synced to/from Fresha';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'fresha_sync_error') THEN
        ALTER TABLE core.services ADD COLUMN fresha_sync_error text COLLATE "C" NULL;
        COMMENT ON COLUMN core.services.fresha_sync_error IS 'Last error encountered during Fresha sync';
    END IF;
END $$;

-- Create indexes if they don't exist
CREATE INDEX IF NOT EXISTS services_fresha_service_id_idx ON core.services(fresha_service_id);
CREATE INDEX IF NOT EXISTS services_fresha_variation_id_idx ON core.services(fresha_variation_id);

-- Create unique constraint if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'services_professional_fresha_variation_uq'
        AND connamespace = 'core'::regnamespace
    ) THEN
        ALTER TABLE core.services
        ADD CONSTRAINT services_professional_fresha_variation_uq UNIQUE (professional_id, fresha_variation_id);
    END IF;
END $$;