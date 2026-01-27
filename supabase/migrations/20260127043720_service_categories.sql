-- If you rely on gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- 1) Create categories table FIRST
CREATE TABLE IF NOT EXISTS core.service_categories (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

  professional_id uuid NOT NULL
    REFERENCES core.professionals(id) ON DELETE CASCADE,

  title text NOT NULL,
  sort_order integer NOT NULL DEFAULT 0,

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  deleted_at timestamptz NULL
);

-- 2) Composite unique key needed for composite FK from services
ALTER TABLE core.service_categories
  DROP CONSTRAINT IF EXISTS service_categories_id_professional_unique;

ALTER TABLE core.service_categories
  ADD CONSTRAINT service_categories_id_professional_unique
  UNIQUE (id, professional_id);

-- 3) Updated-at trigger function + trigger (safe to re-run)
CREATE OR REPLACE FUNCTION core.set_updated_at()
RETURNS trigger AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_service_categories_updated_at ON core.service_categories;

CREATE TRIGGER trg_service_categories_updated_at
BEFORE UPDATE ON core.service_categories
FOR EACH ROW EXECUTE FUNCTION core.set_updated_at();

-- 4) Constraints + indexes
DROP INDEX IF EXISTS core.service_categories_unique_title_per_professional;

CREATE UNIQUE INDEX service_categories_unique_title_per_professional
  ON core.service_categories (professional_id, lower(title))
  WHERE deleted_at IS NULL;

ALTER TABLE core.service_categories
  DROP CONSTRAINT IF EXISTS service_categories_sort_order_non_negative;

ALTER TABLE core.service_categories
  ADD CONSTRAINT service_categories_sort_order_non_negative
  CHECK (sort_order >= 0);

CREATE INDEX IF NOT EXISTS service_categories_professional_sort_idx
  ON core.service_categories (professional_id, sort_order);

-- 5) Add category_id to services (then index)
ALTER TABLE core.services
  ADD COLUMN IF NOT EXISTS category_id uuid NULL;

CREATE INDEX IF NOT EXISTS services_professional_category_sort_idx
  ON core.services (professional_id, category_id, sort_order);

-- 6) Enforce "category belongs to same professional" (composite FK)
ALTER TABLE core.services
  DROP CONSTRAINT IF EXISTS services_category_id_fkey;

ALTER TABLE core.services
  DROP CONSTRAINT IF EXISTS services_category_
