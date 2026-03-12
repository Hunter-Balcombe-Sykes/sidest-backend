-- Enterprise model for promoters / salons / barbershops + ambassador contracting + promoter commerce linkage.
-- This migration is additive and backwards-compatible with the current professional-centric schema.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'professionals'
    ) THEN
        RAISE EXCEPTION 'core.professionals table does not exist.';
    END IF;
END $$;

-- ============================================================
-- 1) core.enterprises
-- ============================================================
CREATE TABLE IF NOT EXISTS core.enterprises (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    auth_user_id        uuid,
    name                text NOT NULL,
    handle              text,
    primary_email       text,
    phone               text,
    public_contact_email text,
    public_contact_number text,
    country_code        text,
    timezone            text,
    location_street_address text,
    location_city       text,
    location_state      text,
    location_postcode   text,
    location_country    text,
    enterprise_type     text NOT NULL,
    status              text NOT NULL DEFAULT 'active',
    subscription_tier   text,
    metadata            jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    deleted_at          timestamptz,
    CONSTRAINT enterprises_type_check
        CHECK (enterprise_type IN ('promoter', 'salon', 'barbershop')),
    CONSTRAINT enterprises_status_check
        CHECK (status IN ('active', 'inactive', 'suspended'))
);

ALTER TABLE core.enterprises OWNER TO postgres;

COMMENT ON TABLE core.enterprises IS 'Top-level business entities (promoters, salons, barbershops).';
COMMENT ON COLUMN core.enterprises.subscription_tier IS 'Enterprise subscription tier/plan key.';
COMMENT ON COLUMN core.enterprises.metadata IS 'Flexible metadata for enterprise-specific integrations and context.';
COMMENT ON COLUMN core.enterprises.auth_user_id IS 'Optional primary auth owner for self-service enterprise account endpoints.';

CREATE UNIQUE INDEX IF NOT EXISTS enterprises_auth_user_active_uq
    ON core.enterprises (auth_user_id)
    WHERE auth_user_id IS NOT NULL
      AND deleted_at IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS enterprises_handle_lc_uq
    ON core.enterprises ((lower(handle)))
    WHERE handle IS NOT NULL
      AND deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS enterprises_type_status_idx
    ON core.enterprises (enterprise_type, status)
    WHERE deleted_at IS NULL;

DROP TRIGGER IF EXISTS trg_enterprises_set_updated_at ON core.enterprises;
CREATE TRIGGER trg_enterprises_set_updated_at
BEFORE UPDATE ON core.enterprises
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- Convenience pointer only. Source of truth is core.professional_enterprise_memberships.
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS primary_enterprise_id uuid;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'professionals_primary_enterprise_id_fkey'
          AND connamespace = 'core'::regnamespace
    ) THEN
        ALTER TABLE core.professionals
            ADD CONSTRAINT professionals_primary_enterprise_id_fkey
            FOREIGN KEY (primary_enterprise_id)
            REFERENCES core.enterprises(id)
            ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS professionals_primary_enterprise_id_idx
    ON core.professionals (primary_enterprise_id);

COMMENT ON COLUMN core.professionals.primary_enterprise_id IS 'Optional convenience FK to a professional''s primary enterprise. Membership table remains source of truth.';

-- ============================================================
-- 2) core.professional_enterprise_memberships
-- ============================================================
CREATE TABLE IF NOT EXISTS core.professional_enterprise_memberships (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    enterprise_id       uuid NOT NULL REFERENCES core.enterprises(id) ON DELETE CASCADE,
    relationship_type   text NOT NULL DEFAULT 'member',
    is_primary          boolean NOT NULL DEFAULT false,
    starts_at           timestamptz NOT NULL DEFAULT now(),
    ends_at             timestamptz,
    metadata            jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT professional_enterprise_memberships_relationship_type_check
        CHECK (relationship_type IN ('owner', 'employee', 'chair_renter', 'contractor', 'affiliate', 'member')),
    CONSTRAINT professional_enterprise_memberships_dates_check
        CHECK (ends_at IS NULL OR ends_at > starts_at)
);

ALTER TABLE core.professional_enterprise_memberships OWNER TO postgres;

COMMENT ON TABLE core.professional_enterprise_memberships IS 'Time-bound relationship between professionals and enterprises.';

CREATE INDEX IF NOT EXISTS pem_professional_idx
    ON core.professional_enterprise_memberships (professional_id, starts_at DESC);

CREATE INDEX IF NOT EXISTS pem_enterprise_idx
    ON core.professional_enterprise_memberships (enterprise_id, starts_at DESC);

CREATE UNIQUE INDEX IF NOT EXISTS pem_professional_enterprise_active_uq
    ON core.professional_enterprise_memberships (professional_id, enterprise_id)
    WHERE ends_at IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS pem_professional_primary_active_uq
    ON core.professional_enterprise_memberships (professional_id)
    WHERE is_primary = true
      AND ends_at IS NULL;

DROP TRIGGER IF EXISTS trg_professional_enterprise_memberships_set_updated_at ON core.professional_enterprise_memberships;
CREATE TRIGGER trg_professional_enterprise_memberships_set_updated_at
BEFORE UPDATE ON core.professional_enterprise_memberships
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 3) core.influencer_promoter_contracts
-- ============================================================
CREATE TABLE IF NOT EXISTS core.influencer_promoter_contracts (
    id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    influencer_professional_id  uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    promoter_enterprise_id      uuid NOT NULL REFERENCES core.enterprises(id) ON DELETE CASCADE,
    status                      text NOT NULL DEFAULT 'active',
    exclusive                   boolean NOT NULL DEFAULT true,
    starts_at                   timestamptz NOT NULL DEFAULT now(),
    ends_at                     timestamptz,
    notes                       text,
    metadata                    jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT influencer_promoter_contracts_status_check
        CHECK (status IN ('draft', 'active', 'paused', 'ended', 'terminated')),
    CONSTRAINT influencer_promoter_contracts_dates_check
        CHECK (ends_at IS NULL OR ends_at > starts_at)
);

ALTER TABLE core.influencer_promoter_contracts OWNER TO postgres;

COMMENT ON TABLE core.influencer_promoter_contracts IS 'Contract history linking ambassadors to promoter enterprises.';

CREATE INDEX IF NOT EXISTS ipc_influencer_idx
    ON core.influencer_promoter_contracts (influencer_professional_id, starts_at DESC);

CREATE INDEX IF NOT EXISTS ipc_promoter_idx
    ON core.influencer_promoter_contracts (promoter_enterprise_id, starts_at DESC);

CREATE UNIQUE INDEX IF NOT EXISTS ipc_one_active_exclusive_contract_uq
    ON core.influencer_promoter_contracts (influencer_professional_id)
    WHERE exclusive = true
      AND status = 'active'
      AND ends_at IS NULL;

DROP TRIGGER IF EXISTS trg_influencer_promoter_contracts_set_updated_at ON core.influencer_promoter_contracts;
CREATE TRIGGER trg_influencer_promoter_contracts_set_updated_at
BEFORE UPDATE ON core.influencer_promoter_contracts
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE FUNCTION core.validate_influencer_promoter_contract()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    influencer_type text;
    promoter_type   text;
BEGIN
    SELECT p.professional_type
      INTO influencer_type
      FROM core.professionals p
     WHERE p.id = NEW.influencer_professional_id
       AND p.deleted_at IS NULL;

    IF COALESCE(influencer_type, '') NOT IN ('ambassador', 'influencer') THEN
        RAISE EXCEPTION 'influencer_professional_id must reference a professional_type = ambassador'
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT e.enterprise_type
      INTO promoter_type
      FROM core.enterprises e
     WHERE e.id = NEW.promoter_enterprise_id
       AND e.deleted_at IS NULL;

    IF promoter_type IS DISTINCT FROM 'promoter' THEN
        RAISE EXCEPTION 'promoter_enterprise_id must reference an enterprise_type = promoter'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_influencer_promoter_contract ON core.influencer_promoter_contracts;
CREATE TRIGGER trg_validate_influencer_promoter_contract
BEFORE INSERT OR UPDATE OF influencer_professional_id, promoter_enterprise_id
ON core.influencer_promoter_contracts
FOR EACH ROW
EXECUTE FUNCTION core.validate_influencer_promoter_contract();

-- ============================================================
-- 4) Backfill enterprises from existing promoter data (if present)
-- ============================================================
DO $$
BEGIN
    -- If a legacy dataset already has professional_type='promoter', migrate those rows.
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'core'
          AND table_name = 'professionals'
          AND column_name = 'professional_type'
    ) THEN
        INSERT INTO core.enterprises (
            auth_user_id,
            name,
            handle,
            enterprise_type,
            status,
            metadata
        )
        SELECT
            p.auth_user_id,
            COALESCE(NULLIF(p.display_name, ''), NULLIF(p.handle, ''), 'Promoter ' || left(p.id::text, 8)),
            NULLIF(p.handle, ''),
            'promoter',
            CASE lower(COALESCE(p.status, 'active'))
                WHEN 'active' THEN 'active'
                WHEN 'suspended' THEN 'suspended'
                ELSE 'inactive'
            END,
            jsonb_build_object(
                'source_professional_id', p.id::text,
                'source', 'legacy_professionals_promoter_type'
            )
        FROM core.professionals p
        WHERE lower(COALESCE(p.professional_type, '')) = 'promoter'
          AND p.deleted_at IS NULL
          AND NOT EXISTS (
                SELECT 1
                FROM core.enterprises e
                WHERE e.enterprise_type = 'promoter'
                  AND e.metadata->>'source_professional_id' = p.id::text
          );

        INSERT INTO core.professional_enterprise_memberships (
            professional_id,
            enterprise_id,
            relationship_type,
            is_primary,
            starts_at,
            metadata
        )
        SELECT
            p.id,
            e.id,
            'owner',
            true,
            now(),
            jsonb_build_object('source', 'legacy_professionals_promoter_type')
        FROM core.professionals p
        JOIN core.enterprises e
          ON e.metadata->>'source_professional_id' = p.id::text
        WHERE lower(COALESCE(p.professional_type, '')) = 'promoter'
          AND p.deleted_at IS NULL
          AND NOT EXISTS (
                SELECT 1
                FROM core.professional_enterprise_memberships m
                WHERE m.professional_id = p.id
                  AND m.enterprise_id = e.id
                  AND m.ends_at IS NULL
          )
        ON CONFLICT DO NOTHING;

        UPDATE core.professionals p
           SET primary_enterprise_id = e.id
          FROM core.enterprises e
         WHERE e.metadata->>'source_professional_id' = p.id::text
           AND lower(COALESCE(p.professional_type, '')) = 'promoter'
           AND p.primary_enterprise_id IS NULL;
    END IF;
END $$;

DO $$
DECLARE
    legacy_column text;
BEGIN
    -- If legacy direct promoter FK columns exist, backfill and then stop relying on those columns at runtime.
    FOR legacy_column IN
        SELECT c.column_name
        FROM information_schema.columns c
        WHERE c.table_schema = 'core'
          AND c.table_name = 'professionals'
          AND c.udt_name = 'uuid'
          AND c.column_name IN ('promoter_id', 'promoter_professional_id')
    LOOP
        EXECUTE format($sql$
            INSERT INTO core.enterprises (
                auth_user_id,
                name,
                handle,
                enterprise_type,
                status,
                metadata
            )
            SELECT DISTINCT
                promoter.auth_user_id,
                COALESCE(NULLIF(promoter.display_name, ''), NULLIF(promoter.handle, ''), 'Promoter ' || left(promoter.id::text, 8)),
                NULLIF(promoter.handle, ''),
                'promoter',
                CASE lower(COALESCE(promoter.status, 'active'))
                    WHEN 'active' THEN 'active'
                    WHEN 'suspended' THEN 'suspended'
                    ELSE 'inactive'
                END,
                jsonb_build_object(
                    'source_professional_id', promoter.id::text,
                    'source', %L
                )
            FROM core.professionals pro
            JOIN core.professionals promoter
              ON pro.%I = promoter.id
            WHERE pro.%I IS NOT NULL
              AND promoter.deleted_at IS NULL
              AND NOT EXISTS (
                    SELECT 1
                    FROM core.enterprises e
                    WHERE e.enterprise_type = 'promoter'
                      AND e.metadata->>'source_professional_id' = promoter.id::text
              );
        $sql$, 'legacy_' || legacy_column, legacy_column, legacy_column);

        EXECUTE format($sql$
            INSERT INTO core.professional_enterprise_memberships (
                professional_id,
                enterprise_id,
                relationship_type,
                is_primary,
                starts_at,
                metadata
            )
            SELECT DISTINCT
                pro.id,
                e.id,
                'affiliate',
                false,
                now(),
                jsonb_build_object('source', %L)
            FROM core.professionals pro
            JOIN core.professionals promoter
              ON pro.%I = promoter.id
            JOIN core.enterprises e
              ON e.enterprise_type = 'promoter'
             AND e.metadata->>'source_professional_id' = promoter.id::text
            WHERE pro.%I IS NOT NULL
              AND NOT EXISTS (
                    SELECT 1
                    FROM core.professional_enterprise_memberships m
                    WHERE m.professional_id = pro.id
                      AND m.enterprise_id = e.id
                      AND m.ends_at IS NULL
              );
        $sql$, 'legacy_' || legacy_column, legacy_column, legacy_column);

        EXECUTE format($sql$
            UPDATE core.professionals pro
               SET primary_enterprise_id = e.id
              FROM core.professionals promoter
              JOIN core.enterprises e
                ON e.enterprise_type = 'promoter'
               AND e.metadata->>'source_professional_id' = promoter.id::text
             WHERE pro.%I = promoter.id
               AND pro.primary_enterprise_id IS NULL;
        $sql$, legacy_column);
    END LOOP;
END $$;

-- Ensure any professional with primary_enterprise_id has at least one active membership row.
INSERT INTO core.professional_enterprise_memberships (
    professional_id,
    enterprise_id,
    relationship_type,
    is_primary,
    starts_at,
    metadata
)
SELECT
    p.id,
    p.primary_enterprise_id,
    'member',
    NOT EXISTS (
        SELECT 1
        FROM core.professional_enterprise_memberships existing_primary
        WHERE existing_primary.professional_id = p.id
          AND existing_primary.is_primary = true
          AND existing_primary.ends_at IS NULL
    ),
    now(),
    jsonb_build_object('source', 'primary_enterprise_id')
FROM core.professionals p
WHERE p.primary_enterprise_id IS NOT NULL
  AND NOT EXISTS (
        SELECT 1
        FROM core.professional_enterprise_memberships m
        WHERE m.professional_id = p.id
          AND m.enterprise_id = p.primary_enterprise_id
          AND m.ends_at IS NULL
  );

-- Keep membership coverage aligned with active ambassador contracts.
INSERT INTO core.professional_enterprise_memberships (
    professional_id,
    enterprise_id,
    relationship_type,
    is_primary,
    starts_at,
    metadata
)
SELECT
    c.influencer_professional_id,
    c.promoter_enterprise_id,
    'affiliate',
    false,
    c.starts_at,
    jsonb_build_object('source', 'influencer_promoter_contract')
FROM core.influencer_promoter_contracts c
WHERE c.status = 'active'
  AND c.starts_at <= now()
  AND (c.ends_at IS NULL OR c.ends_at > now())
  AND NOT EXISTS (
        SELECT 1
        FROM core.professional_enterprise_memberships m
        WHERE m.professional_id = c.influencer_professional_id
          AND m.enterprise_id = c.promoter_enterprise_id
          AND m.ends_at IS NULL
  );

-- ============================================================
-- 5) Promoter commerce tables in retail schema
-- ============================================================
CREATE SCHEMA IF NOT EXISTS retail;
ALTER SCHEMA retail OWNER TO postgres;

CREATE TABLE IF NOT EXISTS retail.enterprise_shopify_accounts (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    enterprise_id       uuid NOT NULL REFERENCES core.enterprises(id) ON DELETE CASCADE,
    shop_domain         text NOT NULL,
    shop_name           text,
    external_shop_id    text,
    token_reference     text,
    is_primary          boolean NOT NULL DEFAULT false,
    is_active           boolean NOT NULL DEFAULT true,
    connected_at        timestamptz,
    metadata            jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE retail.enterprise_shopify_accounts OWNER TO postgres;

COMMENT ON TABLE retail.enterprise_shopify_accounts IS 'Shopify shops connected to promoter enterprises.';
COMMENT ON COLUMN retail.enterprise_shopify_accounts.token_reference IS 'Reference to secure token storage (do not store raw Shopify token in plain text).';

CREATE UNIQUE INDEX IF NOT EXISTS esa_shop_domain_uq
    ON retail.enterprise_shopify_accounts ((lower(shop_domain)));

CREATE UNIQUE INDEX IF NOT EXISTS esa_enterprise_external_shop_uq
    ON retail.enterprise_shopify_accounts (enterprise_id, external_shop_id)
    WHERE external_shop_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS esa_one_primary_per_enterprise_uq
    ON retail.enterprise_shopify_accounts (enterprise_id)
    WHERE is_primary = true
      AND is_active = true;

CREATE INDEX IF NOT EXISTS esa_enterprise_active_idx
    ON retail.enterprise_shopify_accounts (enterprise_id, is_active);

DROP TRIGGER IF EXISTS trg_enterprise_shopify_accounts_set_updated_at ON retail.enterprise_shopify_accounts;
CREATE TRIGGER trg_enterprise_shopify_accounts_set_updated_at
BEFORE UPDATE ON retail.enterprise_shopify_accounts
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TABLE IF NOT EXISTS retail.enterprise_brands (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    enterprise_id       uuid NOT NULL REFERENCES core.enterprises(id) ON DELETE CASCADE,
    name                text NOT NULL,
    slug                text,
    description         text,
    is_active           boolean NOT NULL DEFAULT true,
    metadata            jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE retail.enterprise_brands OWNER TO postgres;

COMMENT ON TABLE retail.enterprise_brands IS 'Brands managed by promoter enterprises.';

CREATE UNIQUE INDEX IF NOT EXISTS eb_enterprise_name_uq
    ON retail.enterprise_brands (enterprise_id, lower(name));

CREATE UNIQUE INDEX IF NOT EXISTS eb_enterprise_slug_uq
    ON retail.enterprise_brands (enterprise_id, lower(slug))
    WHERE slug IS NOT NULL;

CREATE INDEX IF NOT EXISTS eb_enterprise_active_idx
    ON retail.enterprise_brands (enterprise_id, is_active);

DROP TRIGGER IF EXISTS trg_enterprise_brands_set_updated_at ON retail.enterprise_brands;
CREATE TRIGGER trg_enterprise_brands_set_updated_at
BEFORE UPDATE ON retail.enterprise_brands
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TABLE IF NOT EXISTS retail.enterprise_products (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    enterprise_id           uuid NOT NULL REFERENCES core.enterprises(id) ON DELETE CASCADE,
    shopify_account_id      uuid REFERENCES retail.enterprise_shopify_accounts(id) ON DELETE SET NULL,
    brand_id                uuid REFERENCES retail.enterprise_brands(id) ON DELETE SET NULL,
    shopify_product_id      text NOT NULL,
    title                   text NOT NULL,
    handle                  text,
    product_url             text,
    image_url               text,
    price_cents             integer,
    currency_code           char(3) NOT NULL DEFAULT 'AUD',
    is_active               boolean NOT NULL DEFAULT true,
    metadata                jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT enterprise_products_price_cents_check
        CHECK (price_cents IS NULL OR price_cents >= 0)
);

ALTER TABLE retail.enterprise_products OWNER TO postgres;

COMMENT ON TABLE retail.enterprise_products IS 'Promoter-enterprise product catalog sourced from connected Shopify accounts.';

CREATE UNIQUE INDEX IF NOT EXISTS ep_enterprise_shopify_product_uq
    ON retail.enterprise_products (enterprise_id, shopify_product_id);

CREATE INDEX IF NOT EXISTS ep_enterprise_active_idx
    ON retail.enterprise_products (enterprise_id, is_active, created_at DESC);

CREATE INDEX IF NOT EXISTS ep_shopify_account_idx
    ON retail.enterprise_products (shopify_account_id, created_at DESC);

DROP TRIGGER IF EXISTS trg_enterprise_products_set_updated_at ON retail.enterprise_products;
CREATE TRIGGER trg_enterprise_products_set_updated_at
BEFORE UPDATE ON retail.enterprise_products
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 6) Link retail selections / sale events to enterprise
-- ============================================================
ALTER TABLE retail.professional_selections
    ADD COLUMN IF NOT EXISTS enterprise_id uuid;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'professional_selections_enterprise_id_fkey'
          AND connamespace = 'retail'::regnamespace
    ) THEN
        ALTER TABLE retail.professional_selections
            ADD CONSTRAINT professional_selections_enterprise_id_fkey
            FOREIGN KEY (enterprise_id)
            REFERENCES core.enterprises(id)
            ON DELETE SET NULL;
    END IF;
END $$;

DROP INDEX IF EXISTS retail.ps_professional_product_uq;
CREATE UNIQUE INDEX IF NOT EXISTS ps_professional_product_uq
    ON retail.professional_selections (
        professional_id,
        COALESCE(enterprise_id, '00000000-0000-0000-0000-000000000000'::uuid),
        shopify_product_id
    );

CREATE INDEX IF NOT EXISTS ps_professional_enterprise_sort
    ON retail.professional_selections (professional_id, enterprise_id, sort_order);

CREATE OR REPLACE FUNCTION retail.validate_selection_enterprise_link()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    professional_type text;
    enterprise_type   text;
    has_link          boolean;
BEGIN
    IF NEW.enterprise_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT e.enterprise_type
      INTO enterprise_type
      FROM core.enterprises e
     WHERE e.id = NEW.enterprise_id
       AND e.deleted_at IS NULL;

    IF enterprise_type IS NULL THEN
        RAISE EXCEPTION 'Selected enterprise does not exist or has been deleted.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF enterprise_type <> 'promoter' THEN
        RAISE EXCEPTION 'Product selections can only be linked to promoter enterprises.'
            USING ERRCODE = 'check_violation';
    END IF;

    SELECT p.professional_type
      INTO professional_type
      FROM core.professionals p
     WHERE p.id = NEW.professional_id
       AND p.deleted_at IS NULL;

    IF professional_type IS NULL THEN
        RAISE EXCEPTION 'Professional does not exist or has been deleted.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF professional_type IN ('ambassador', 'influencer') THEN
        SELECT EXISTS (
            SELECT 1
            FROM core.influencer_promoter_contracts c
            WHERE c.influencer_professional_id = NEW.professional_id
              AND c.promoter_enterprise_id = NEW.enterprise_id
              AND c.status = 'active'
              AND c.starts_at <= now()
              AND (c.ends_at IS NULL OR c.ends_at > now())
        )
        INTO has_link;
    ELSE
        SELECT EXISTS (
            SELECT 1
            FROM core.professional_enterprise_memberships m
            WHERE m.professional_id = NEW.professional_id
              AND m.enterprise_id = NEW.enterprise_id
              AND m.starts_at <= now()
              AND (m.ends_at IS NULL OR m.ends_at > now())
        )
        INTO has_link;
    END IF;

    IF NOT has_link THEN
        RAISE EXCEPTION 'Professional is not actively linked to this promoter enterprise.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_selection_enterprise_link ON retail.professional_selections;
CREATE TRIGGER trg_validate_selection_enterprise_link
BEFORE INSERT OR UPDATE OF professional_id, enterprise_id
ON retail.professional_selections
FOR EACH ROW
EXECUTE FUNCTION retail.validate_selection_enterprise_link();

ALTER TABLE retail.sale_events
    ADD COLUMN IF NOT EXISTS enterprise_id uuid;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'sale_events_enterprise_id_fkey'
          AND connamespace = 'retail'::regnamespace
    ) THEN
        ALTER TABLE retail.sale_events
            ADD CONSTRAINT sale_events_enterprise_id_fkey
            FOREIGN KEY (enterprise_id)
            REFERENCES core.enterprises(id)
            ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS se_enterprise_recorded_idx
    ON retail.sale_events (enterprise_id, recorded_at DESC);

-- Backfill ambassador selections to active promoter enterprise where possible.
UPDATE retail.professional_selections ps
SET enterprise_id = contract.promoter_enterprise_id
FROM core.professionals p
JOIN LATERAL (
    SELECT c.promoter_enterprise_id
    FROM core.influencer_promoter_contracts c
    WHERE c.influencer_professional_id = p.id
      AND c.status = 'active'
      AND c.starts_at <= now()
      AND (c.ends_at IS NULL OR c.ends_at > now())
    ORDER BY c.starts_at DESC
    LIMIT 1
) contract ON true
WHERE ps.professional_id = p.id
  AND lower(COALESCE(p.professional_type, '')) IN ('ambassador', 'influencer')
  AND ps.enterprise_id IS NULL;

-- ============================================================
-- 7) Grants for runtime role
-- ============================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA core TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE core.enterprises TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE core.professional_enterprise_memberships TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE core.influencer_promoter_contracts TO app_backend';

        EXECUTE 'GRANT USAGE ON SCHEMA retail TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.enterprise_shopify_accounts TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.enterprise_brands TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.enterprise_products TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.professional_selections TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.sale_events TO app_backend';

        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA retail GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
    END IF;
END $$;

COMMIT;
