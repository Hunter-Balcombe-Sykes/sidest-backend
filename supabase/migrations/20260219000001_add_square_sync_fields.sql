-- Add Square catalog sync tracking fields (idempotent)

-- Professionals: track last sync time and errors
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'square_catalog_latest_time') THEN
        ALTER TABLE core.professionals ADD COLUMN square_catalog_latest_time timestamp with time zone NULL;
        COMMENT ON COLUMN core.professionals.square_catalog_latest_time IS 'Latest catalog version timestamp from Square API';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'square_last_catalog_sync_at') THEN
        ALTER TABLE core.professionals ADD COLUMN square_last_catalog_sync_at timestamp with time zone NULL;
        COMMENT ON COLUMN core.professionals.square_last_catalog_sync_at IS 'When we last synced catalog from Square';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'square_last_catalog_sync_error') THEN
        ALTER TABLE core.professionals ADD COLUMN square_last_catalog_sync_error text COLLATE "C" NULL;
        COMMENT ON COLUMN core.professionals.square_last_catalog_sync_error IS 'Last error encountered during catalog sync';
    END IF;
END $$;

-- Services: track Square sync state and IDs
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'square_catalog_object_id') THEN
        ALTER TABLE core.services ADD COLUMN square_catalog_object_id varchar(255) COLLATE "C" NULL;
        COMMENT ON COLUMN core.services.square_catalog_object_id IS 'Square catalog object ID for this service';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'square_variation_id') THEN
        ALTER TABLE core.services ADD COLUMN square_variation_id varchar(255) COLLATE "C" NULL;
        COMMENT ON COLUMN core.services.square_variation_id IS 'Square variation ID for this service';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'square_catalog_version') THEN
        ALTER TABLE core.services ADD COLUMN square_catalog_version bigint NULL;
        COMMENT ON COLUMN core.services.square_catalog_version IS 'Version from Square catalog_version field';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'square_last_synced_at') THEN
        ALTER TABLE core.services ADD COLUMN square_last_synced_at timestamp with time zone NULL;
        COMMENT ON COLUMN core.services.square_last_synced_at IS 'When this service was last synced to/from Square';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'services' AND column_name = 'square_sync_error') THEN
        ALTER TABLE core.services ADD COLUMN square_sync_error text COLLATE "C" NULL;
        COMMENT ON COLUMN core.services.square_sync_error IS 'Last error encountered during Square sync';
    END IF;
END $$;

-- Create indexes if they don't exist
CREATE INDEX IF NOT EXISTS services_square_catalog_object_id_idx ON core.services(square_catalog_object_id);
CREATE INDEX IF NOT EXISTS services_square_variation_id_idx ON core.services(square_variation_id);

-- Create unique constraint if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'services_professional_square_variation_uq'
        AND connamespace = 'core'::regnamespace
    ) THEN
        ALTER TABLE core.services
        ADD CONSTRAINT services_professional_square_variation_uq UNIQUE (professional_id, square_variation_id);
    END IF;
END $$;
