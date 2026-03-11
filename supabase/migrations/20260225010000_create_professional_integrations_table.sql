-- Create provider-agnostic professional integrations table and backfill Square/Fresha data.

CREATE TABLE IF NOT EXISTS core.professional_integrations (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    provider varchar(64) COLLATE "C" NOT NULL,
    external_account_id varchar(255) COLLATE "C" NULL,
    access_token text COLLATE "C" NULL,
    refresh_token text COLLATE "C" NULL,
    expires_at timestamp with time zone NULL,
    catalog_latest_time timestamp with time zone NULL,
    last_catalog_sync_at timestamp with time zone NULL,
    last_catalog_sync_error text COLLATE "C" NULL,
    provider_metadata jsonb NULL,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now()
);

-- One integration row per provider per professional.
CREATE UNIQUE INDEX IF NOT EXISTS professional_integrations_professional_provider_uq
    ON core.professional_integrations(professional_id, provider);

-- Fast webhook/job/provider lookups.
CREATE INDEX IF NOT EXISTS professional_integrations_provider_account_idx
    ON core.professional_integrations(provider, external_account_id);

CREATE INDEX IF NOT EXISTS professional_integrations_professional_idx
    ON core.professional_integrations(professional_id);

-- Keep updated_at current.
CREATE OR REPLACE FUNCTION core.set_professional_integrations_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_professional_integrations_set_updated_at ON core.professional_integrations;
CREATE TRIGGER trg_professional_integrations_set_updated_at
BEFORE UPDATE ON core.professional_integrations
FOR EACH ROW
EXECUTE FUNCTION core.set_professional_integrations_updated_at();

-- Backfill Square rows from existing professionals columns.
INSERT INTO core.professional_integrations (
    professional_id,
    provider,
    external_account_id,
    access_token,
    refresh_token,
    expires_at,
    catalog_latest_time,
    last_catalog_sync_at,
    last_catalog_sync_error
)
SELECT
    p.id,
    'square',
    p.square_merchant_id,
    p.square_access_token,
    p.square_refresh_token,
    p.square_expires_at,
    p.square_catalog_latest_time,
    p.square_last_catalog_sync_at,
    p.square_last_catalog_sync_error
FROM core.professionals p
WHERE
    p.square_access_token IS NOT NULL
    OR p.square_refresh_token IS NOT NULL
    OR p.square_merchant_id IS NOT NULL
ON CONFLICT (professional_id, provider)
DO UPDATE SET
    external_account_id = EXCLUDED.external_account_id,
    access_token = EXCLUDED.access_token,
    refresh_token = EXCLUDED.refresh_token,
    expires_at = EXCLUDED.expires_at,
    catalog_latest_time = EXCLUDED.catalog_latest_time,
    last_catalog_sync_at = EXCLUDED.last_catalog_sync_at,
    last_catalog_sync_error = EXCLUDED.last_catalog_sync_error,
    updated_at = now();

-- Backfill Fresha rows from existing professionals columns.
INSERT INTO core.professional_integrations (
    professional_id,
    provider,
    external_account_id,
    access_token,
    refresh_token,
    expires_at,
    catalog_latest_time,
    last_catalog_sync_at,
    last_catalog_sync_error
)
SELECT
    p.id,
    'fresha',
    p.fresha_business_id,
    p.fresha_access_token,
    p.fresha_refresh_token,
    p.fresha_expires_at,
    p.fresha_catalog_latest_time,
    p.fresha_last_catalog_sync_at,
    p.fresha_last_catalog_sync_error
FROM core.professionals p
WHERE
    p.fresha_access_token IS NOT NULL
    OR p.fresha_refresh_token IS NOT NULL
    OR p.fresha_business_id IS NOT NULL
ON CONFLICT (professional_id, provider)
DO UPDATE SET
    external_account_id = EXCLUDED.external_account_id,
    access_token = EXCLUDED.access_token,
    refresh_token = EXCLUDED.refresh_token,
    expires_at = EXCLUDED.expires_at,
    catalog_latest_time = EXCLUDED.catalog_latest_time,
    last_catalog_sync_at = EXCLUDED.last_catalog_sync_at,
    last_catalog_sync_error = EXCLUDED.last_catalog_sync_error,
    updated_at = now();
