-- Add Square catalog sync tracking fields

-- Professionals: track last sync time and errors
ALTER TABLE core.professionals
ADD COLUMN square_catalog_latest_time timestamp with time zone COLLATE "C" NULL,
ADD COLUMN square_last_catalog_sync_at timestamp with time zone COLLATE "C" NULL,
ADD COLUMN square_last_catalog_sync_error text COLLATE "C" NULL;

COMMENT ON COLUMN core.professionals.square_catalog_latest_time IS 'Latest catalog version timestamp from Square API';
COMMENT ON COLUMN core.professionals.square_last_catalog_sync_at IS 'When we last synced catalog from Square';
COMMENT ON COLUMN core.professionals.square_last_catalog_sync_error IS 'Last error encountered during catalog sync';

-- Services: track Square sync state and IDs
ALTER TABLE core.services
ADD COLUMN square_catalog_object_id varchar(255) COLLATE "C" NULL,
ADD COLUMN square_variation_id varchar(255) COLLATE "C" NULL,
ADD COLUMN square_catalog_version bigint NULL,
ADD COLUMN square_last_synced_at timestamp with time zone COLLATE "C" NULL,
ADD COLUMN square_sync_error text COLLATE "C" NULL;

-- Create index on square_catalog_object_id for lookups
CREATE INDEX IF NOT EXISTS services_square_catalog_object_id_idx ON core.services(square_catalog_object_id);

-- Create index on square_variation_id for lookups
CREATE INDEX IF NOT EXISTS services_square_variation_id_idx ON core.services(square_variation_id);

-- Create unique constraint: professional can only have one service per Square variation
ALTER TABLE core.services
ADD CONSTRAINT services_professional_square_variation_uq UNIQUE (professional_id, square_variation_id);

COMMENT ON COLUMN core.services.square_catalog_object_id IS 'Square catalog object ID for this service';
COMMENT ON COLUMN core.services.square_variation_id IS 'Square variation ID for this service';
COMMENT ON COLUMN core.services.square_catalog_version IS 'Version from Square catalog_version field';
COMMENT ON COLUMN core.services.square_last_synced_at IS 'When this service was last synced to/from Square';
COMMENT ON COLUMN core.services.square_sync_error IS 'Last error encountered during Square sync';
