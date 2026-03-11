-- Add pool column to site_images and create image_variants table
-- for server-side WebP variant generation.

-- 1. Add `pool` column to site_images (gallery | content)
ALTER TABLE core.site_images
    ADD COLUMN IF NOT EXISTS pool varchar(20) NOT NULL DEFAULT 'gallery';

COMMENT ON COLUMN core.site_images.pool IS 'Image pool: gallery or content';

CREATE INDEX IF NOT EXISTS si_pool_active
    ON core.site_images(site_id, pool, is_active);

-- 2. Image variants – one row per processed WebP size
CREATE TABLE IF NOT EXISTS core.image_variants (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    image_id uuid NOT NULL REFERENCES core.site_images(id) ON DELETE CASCADE,
    variant varchar(20) NOT NULL,            -- thumb, small, medium, large, hero
    disk varchar(40) NOT NULL DEFAULT 'media',
    path varchar(500) NOT NULL,              -- e.g. images/<proId>/<imageId>/thumb_abc123.webp
    format varchar(10) NOT NULL DEFAULT 'webp',
    width integer NOT NULL,
    height integer NOT NULL,
    file_size integer NOT NULL,              -- bytes
    content_hash varchar(16) NOT NULL,       -- first 8 bytes of sha256, hex-encoded
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now()
);

ALTER TABLE core.image_variants OWNER TO postgres;

COMMENT ON TABLE core.image_variants IS 'Processed WebP variants (thumb/small/medium/large/hero) for each site_image';

-- One variant name per image
CREATE UNIQUE INDEX IF NOT EXISTS iv_image_variant
    ON core.image_variants(image_id, variant);

CREATE INDEX IF NOT EXISTS iv_image_id
    ON core.image_variants(image_id);

-- Keep updated_at current
CREATE OR REPLACE FUNCTION core.set_image_variants_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_image_variants_set_updated_at ON core.image_variants;
CREATE TRIGGER trg_image_variants_set_updated_at
BEFORE UPDATE ON core.image_variants
FOR EACH ROW
EXECUTE FUNCTION core.set_image_variants_updated_at();
