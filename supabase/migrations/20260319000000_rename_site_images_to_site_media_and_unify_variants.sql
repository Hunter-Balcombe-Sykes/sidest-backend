-- Rename core.site_images → core.site_media and merge core.image_variants into
-- core.media_variants. After this migration there is one canonical media table
-- and one canonical variants table. Image variants carry artifact_type = 'webp'.

BEGIN;

-- -------------------------------------------------------------------------
-- 1. Extend media_variants with the content_hash column that image_variants
--    carries. Video artifacts never set this; it will be NULL for them.
-- -------------------------------------------------------------------------
ALTER TABLE core.media_variants
    ADD COLUMN IF NOT EXISTS content_hash varchar(16);

COMMENT ON COLUMN core.media_variants.content_hash
    IS 'SHA-256 hash prefix (16 chars) used in content-addressed image variant paths';

-- -------------------------------------------------------------------------
-- 2. Rename the parent table.
-- -------------------------------------------------------------------------
ALTER TABLE core.site_images RENAME TO site_media;

-- Rename indexes to reflect the new table name.
ALTER INDEX IF EXISTS si_pool_active      RENAME TO sm_pool_active;
ALTER INDEX IF EXISTS si_pool_media_active RENAME TO sm_pool_media_active;

-- -------------------------------------------------------------------------
-- 3. Migrate all image_variants rows into media_variants.
--    Mapping:
--      image_variants.image_id   → media_variants.media_id
--      image_variants.variant    → media_variants.variant_key
--      (implicit webp)           → media_variants.artifact_type = 'webp'
--      image_variants.file_size  → media_variants.file_size_bytes (int → bigint)
--      (implicit)                → media_variants.mime = 'image/webp'
-- -------------------------------------------------------------------------
INSERT INTO core.media_variants
    ( id, media_id, variant_key, artifact_type, disk, path, mime,
      width, height, file_size_bytes, content_hash, created_at, updated_at )
SELECT
    id,
    image_id,
    variant,
    'webp',
    disk,
    path,
    'image/webp',
    width,
    height,
    file_size::bigint,
    content_hash,
    created_at,
    updated_at
FROM core.image_variants
ON CONFLICT (media_id, variant_key, artifact_type) DO NOTHING;

-- -------------------------------------------------------------------------
-- 4. Replace the public_site_payload view with updated table references.
--    site_images  → site_media
--    image_variants join → media_variants WHERE artifact_type = 'webp'
-- -------------------------------------------------------------------------
CREATE OR REPLACE VIEW core.public_site_payload
WITH (security_invoker='on') AS
SELECT
  s.id as site_id,
  s.professional_id,
  s.subdomain,
  jsonb_build_object(
    'site', jsonb_build_object(
      'id', s.id,
      'subdomain', s.subdomain,
      'settings', s.settings,
      'is_published', s.is_published,
      'gallery', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM core.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'webp'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM core.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'gallery'
          AND sm.media_type = 'image'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'content_images', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM core.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'webp'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM core.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'content'
          AND sm.media_type = 'image'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'gallery_videos', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'media_type', sm.media_type,
            'processing_state', sm.processing_state,
            'duration_ms', sm.duration_ms,
            'poster', sm.poster_path,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM core.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'mp4'
            ), '{}'::jsonb),
            'streams', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM core.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'hls_playlist'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM core.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'gallery'
          AND sm.media_type = 'video'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'content_videos', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'media_type', sm.media_type,
            'processing_state', sm.processing_state,
            'duration_ms', sm.duration_ms,
            'poster', sm.poster_path,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM core.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'mp4'
            ), '{}'::jsonb),
            'streams', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM core.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'hls_playlist'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM core.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'content'
          AND sm.media_type = 'video'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb)
    ),
    'professional', jsonb_build_object(
      'id', p.id,
      'handle', p.handle,
      'display_name', p.display_name,
      'bio', p.bio,
      'country_code', p.country_code,
      'timezone', p.timezone,
      'public_contact_number', p.public_contact_number,
      'public_contact_email', p.public_contact_email
    ),
    'theme', CASE WHEN t.id IS NULL THEN NULL::jsonb ELSE jsonb_build_object('id', t.id, 'key', t.key, 'name', t.name, 'config', t.config) END,
    'links', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'settings', b.settings
        )
        ORDER BY b.sort_order, b.created_at
      )
      FROM core.blocks b
      WHERE b.site_id = s.id AND b.block_group = 'links' AND b.is_active = true AND b.deleted_at IS NULL
    ), '[]'::jsonb),
    'sections', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'settings', b.settings
        )
        ORDER BY b.sort_order, b.created_at
      )
      FROM core.blocks b
      WHERE b.site_id = s.id AND b.block_group = 'sections' AND b.is_active = true AND b.deleted_at IS NULL
    ), '[]'::jsonb),
    'services', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', sv.id,
          'title', sv.title,
          'description', sv.description,
          'price_cents', sv.price_cents,
          'currency_code', sv.currency_code,
          'duration_minutes', sv.duration_minutes,
          'is_active', sv.is_active,
          'sort_order', sv.sort_order,
          'category', COALESCE(sc.title, 'Services')
        )
        ORDER BY COALESCE(sc.sort_order, 2147483647), LOWER(COALESCE(sc.title, 'Services')), sv.sort_order, sv.created_at
      )
      FROM core.services sv
      LEFT JOIN core.service_categories sc
        ON sc.id = sv.category_id
        AND sc.deleted_at IS NULL
      WHERE sv.professional_id = p.id AND sv.is_active = true AND sv.deleted_at IS NULL
    ), '[]'::jsonb),
    'legal', CASE
      WHEN plc.professional_id IS NULL THEN NULL::jsonb
      ELSE jsonb_build_object(
        'privacy_policy',
          CASE
            WHEN plc.active_privacy_source = 'manual'
              AND NULLIF(BTRIM(COALESCE(plc.manual_privacy_policy, '')), '') IS NOT NULL
            THEN plc.manual_privacy_policy
            ELSE plc.generated_privacy_policy
          END,
        'terms_and_conditions',
          CASE
            WHEN plc.active_terms_source = 'manual'
              AND NULLIF(BTRIM(COALESCE(plc.manual_terms_and_conditions, '')), '') IS NOT NULL
            THEN plc.manual_terms_and_conditions
            ELSE plc.generated_terms_and_conditions
          END,
        'active_privacy_source', plc.active_privacy_source,
        'active_terms_source', plc.active_terms_source
      )
    END
  ) as payload
FROM core.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN core.themes t ON t.id = s.theme_id
LEFT JOIN core.professional_legal_contents plc ON plc.professional_id = p.id
WHERE
  s.is_published = true
  AND p.status = 'active'
  AND p.deleted_at IS NULL;

COMMENT ON VIEW core.public_site_payload IS 'Complete public site payload with image pools, video pools, services, and active legal content';

-- -------------------------------------------------------------------------
-- 5. Drop image_variants (CASCADE removes its indexes, FK, triggers).
-- -------------------------------------------------------------------------
DROP TABLE core.image_variants CASCADE;

DROP FUNCTION IF EXISTS core.set_image_variants_updated_at();

COMMIT;
