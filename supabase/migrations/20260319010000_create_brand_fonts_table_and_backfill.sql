-- Move brand typography fonts from site.settings JSON into relational storage.
-- Hard cutover migration: creates core.brand_fonts, backfills from legacy JSON,
-- and verifies no legacy-brand font URLs remain without an active DB row.

BEGIN;

CREATE TABLE IF NOT EXISTS core.brand_fonts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL
        REFERENCES core.professionals(id) ON DELETE CASCADE,
    slot text NOT NULL DEFAULT 'primary',
    file_name text,
    file_path text NOT NULL,
    file_url text NOT NULL,
    format text NOT NULL DEFAULT 'woff2',
    file_hash text NOT NULL,
    size_bytes bigint NOT NULL DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    CONSTRAINT brand_fonts_slot_check CHECK (slot IN ('primary')),
    CONSTRAINT brand_fonts_format_check CHECK (lower(format) = 'woff2'),
    CONSTRAINT brand_fonts_size_non_negative_check CHECK (size_bytes >= 0)
);

ALTER TABLE core.brand_fonts OWNER TO postgres;

COMMENT ON TABLE core.brand_fonts IS 'Brand-managed fonts used for themed affiliate/professional sites. Active pointer per brand+slot with version history.';
COMMENT ON COLUMN core.brand_fonts.slot IS 'Font slot key. v1 supports only primary.';
COMMENT ON COLUMN core.brand_fonts.file_hash IS 'File hash metadata (SHA-256 for uploads; deterministic fallback for backfilled rows).';

CREATE UNIQUE INDEX IF NOT EXISTS brand_fonts_active_brand_slot_uq
    ON core.brand_fonts (brand_professional_id, slot)
    WHERE is_active = true AND deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS brand_fonts_brand_created_idx
    ON core.brand_fonts (brand_professional_id, created_at DESC);

CREATE OR REPLACE FUNCTION core.set_brand_fonts_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_brand_fonts_set_updated_at ON core.brand_fonts;
CREATE TRIGGER trg_brand_fonts_set_updated_at
BEFORE UPDATE ON core.brand_fonts
FOR EACH ROW
EXECUTE FUNCTION core.set_brand_fonts_updated_at();

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON core.brand_fonts TO app_backend';
  END IF;
END $$;

-- Backfill one active primary font per brand professional from legacy settings JSON.
-- The insert is idempotent and only runs when no active primary font row exists yet.
WITH legacy_candidates AS (
    SELECT
        s.professional_id AS brand_professional_id,
        NULLIF(trim(s.settings->'design'->'typography'->>'font_file_url'), '') AS legacy_file_url,
        NULLIF(trim(s.settings->'design'->'typography'->>'font_file_path'), '') AS legacy_file_path,
        NULLIF(trim(s.settings->'design'->'typography'->>'font_file_name'), '') AS legacy_file_name
    FROM core.sites s
    JOIN core.professionals p ON p.id = s.professional_id
    WHERE lower(coalesce(p.professional_type, '')) = 'brand'
), normalized AS (
    SELECT
        c.brand_professional_id,
        c.legacy_file_name AS file_name,
        COALESCE(c.legacy_file_path, c.legacy_file_url) AS file_path,
        c.legacy_file_url AS file_url,
        'woff2'::text AS format,
        md5(c.legacy_file_url || '|' || coalesce(c.legacy_file_path, '')) AS file_hash,
        0::bigint AS size_bytes
    FROM legacy_candidates c
    WHERE c.legacy_file_url IS NOT NULL
)
INSERT INTO core.brand_fonts (
    id,
    brand_professional_id,
    slot,
    file_name,
    file_path,
    file_url,
    format,
    file_hash,
    size_bytes,
    is_active,
    created_at,
    updated_at,
    deleted_at
)
SELECT
    gen_random_uuid(),
    n.brand_professional_id,
    'primary',
    n.file_name,
    n.file_path,
    n.file_url,
    n.format,
    n.file_hash,
    n.size_bytes,
    true,
    now(),
    now(),
    NULL
FROM normalized n
WHERE NOT EXISTS (
    SELECT 1
    FROM core.brand_fonts bf
    WHERE bf.brand_professional_id = n.brand_professional_id
      AND bf.slot = 'primary'
      AND bf.is_active = true
      AND bf.deleted_at IS NULL
)
ON CONFLICT DO NOTHING;

-- Hard-cutover guard: fail migration if any brand still has legacy font URL but no active DB font row.
DO $$
DECLARE
    missing_count integer;
BEGIN
    SELECT count(*) INTO missing_count
    FROM (
        WITH legacy_brand_ids AS (
            SELECT s.professional_id
            FROM core.sites s
            JOIN core.professionals p ON p.id = s.professional_id
            WHERE lower(coalesce(p.professional_type, '')) = 'brand'
              AND NULLIF(trim(s.settings->'design'->'typography'->>'font_file_url'), '') IS NOT NULL
        )
        SELECT l.professional_id
        FROM legacy_brand_ids l
        LEFT JOIN core.brand_fonts bf
          ON bf.brand_professional_id = l.professional_id
         AND bf.slot = 'primary'
         AND bf.is_active = true
         AND bf.deleted_at IS NULL
        WHERE bf.id IS NULL
    ) missing;

    IF missing_count > 0 THEN
        RAISE EXCEPTION 'brand_fonts backfill incomplete: % brand profiles still missing an active primary font row.', missing_count;
    END IF;
END $$;

COMMIT;
