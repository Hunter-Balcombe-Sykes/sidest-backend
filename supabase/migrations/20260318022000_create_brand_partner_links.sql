-- Normalize site brand-partner relationships into relational storage.

BEGIN;

CREATE TABLE IF NOT EXISTS core.brand_partner_links (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    slot smallint NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_partner_links_slot_check CHECK (slot BETWEEN 0 AND 3),
    CONSTRAINT brand_partner_links_not_self_check CHECK (affiliate_professional_id <> brand_professional_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS brand_partner_links_affiliate_brand_uq
    ON core.brand_partner_links (affiliate_professional_id, brand_professional_id);

CREATE UNIQUE INDEX IF NOT EXISTS brand_partner_links_affiliate_slot_uq
    ON core.brand_partner_links (affiliate_professional_id, slot);

CREATE INDEX IF NOT EXISTS brand_partner_links_brand_idx
    ON core.brand_partner_links (brand_professional_id);

CREATE INDEX IF NOT EXISTS brand_partner_links_affiliate_idx
    ON core.brand_partner_links (affiliate_professional_id);

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON core.brand_partner_links TO app_backend';
  END IF;
END $$;

-- Backfill from core.sites.settings for any links not yet represented in relational storage.
DO $$
BEGIN
    WITH primary_links AS (
        SELECT
            s.professional_id AS affiliate_professional_id,
            CASE
                WHEN COALESCE(s.settings->'brand_partner'->>'professional_id', s.settings->'brandPartner'->>'professionalId', '') ~* '^[0-9a-f-]{36}$'
                    THEN COALESCE(s.settings->'brand_partner'->>'professional_id', s.settings->'brandPartner'->>'professionalId')::uuid
                ELSE NULL
            END AS brand_professional_id
        FROM core.sites s
    ),
    additional_raw AS (
        SELECT
            s.professional_id AS affiliate_professional_id,
            CASE
                WHEN COALESCE(item.value->>'professional_id', '') ~* '^[0-9a-f-]{36}$'
                    THEN (item.value->>'professional_id')::uuid
                ELSE NULL
            END AS brand_professional_id,
            item.ordinality AS source_position
        FROM core.sites s
        CROSS JOIN LATERAL jsonb_array_elements(COALESCE(s.settings->'additional_brand_partners', '[]'::jsonb))
            WITH ORDINALITY AS item(value, ordinality)
    ),
    additional_filtered AS (
        SELECT
            ar.affiliate_professional_id,
            ar.brand_professional_id,
            ar.source_position
        FROM additional_raw ar
        LEFT JOIN primary_links p
          ON p.affiliate_professional_id = ar.affiliate_professional_id
        WHERE ar.brand_professional_id IS NOT NULL
          AND (p.brand_professional_id IS NULL OR ar.brand_professional_id <> p.brand_professional_id)
    ),
    additional_deduped AS (
        SELECT DISTINCT ON (affiliate_professional_id, brand_professional_id)
            affiliate_professional_id,
            brand_professional_id,
            source_position
        FROM additional_filtered
        ORDER BY affiliate_professional_id, brand_professional_id, source_position
    ),
    additional_ranked AS (
        SELECT
            affiliate_professional_id,
            brand_professional_id,
            ROW_NUMBER() OVER (
                PARTITION BY affiliate_professional_id
                ORDER BY source_position, brand_professional_id::text
            ) AS slot
        FROM additional_deduped
    ),
    candidates AS (
        SELECT
            p.affiliate_professional_id,
            p.brand_professional_id,
            0::smallint AS slot
        FROM primary_links p
        WHERE p.brand_professional_id IS NOT NULL
          AND p.affiliate_professional_id <> p.brand_professional_id

        UNION ALL

        SELECT
            a.affiliate_professional_id,
            a.brand_professional_id,
            a.slot::smallint
        FROM additional_ranked a
        WHERE a.slot <= 3
          AND a.affiliate_professional_id <> a.brand_professional_id
    )
    INSERT INTO core.brand_partner_links (
        id,
        affiliate_professional_id,
        brand_professional_id,
        slot,
        created_at,
        updated_at
    )
    SELECT
        gen_random_uuid(),
        c.affiliate_professional_id,
        c.brand_professional_id,
        c.slot,
        now(),
        now()
    FROM candidates c
    ON CONFLICT (affiliate_professional_id, brand_professional_id)
    DO NOTHING;
END $$;

COMMIT;
