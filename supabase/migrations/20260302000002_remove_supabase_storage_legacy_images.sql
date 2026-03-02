-- Remove all Supabase Storage usage. Images now served via Laravel Cloud Object Storage (R2).
-- This migration:
--   1. Drops Supabase Storage RLS policies (no longer uploading to public-assets bucket)
--   2. Updates public_site_payload view to source images from image_variants + site_images pools
--   3. Updates all_site_data view to remove legacy icon/headshot columns
--   4. Drops the enforce_site_gallery_max6 trigger (replaced by advisory-lock limits in Laravel)
--   5. Drops legacy image columns from professionals and sites tables
--   6. Drops the bucket column from site_images (no longer references Supabase Storage)

-- ============================================================
-- 1. Drop Supabase Storage RLS policies
-- ============================================================
DROP POLICY IF EXISTS "auth read own assets" ON storage.objects;
DROP POLICY IF EXISTS "auth upload own professional assets" ON storage.objects;
DROP POLICY IF EXISTS "auth upload own site assets" ON storage.objects;
DROP POLICY IF EXISTS "auth update own assets" ON storage.objects;
DROP POLICY IF EXISTS "auth delete own assets" ON storage.objects;
DROP POLICY IF EXISTS "public read published site assets" ON storage.objects;
DROP POLICY IF EXISTS "public read professional assets when published" ON storage.objects;

-- ============================================================
-- 2. Rebuild public_site_payload to use image_variants + pools
-- ============================================================
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
            'id', si.id,
            'alt_text', si.alt_text,
            'sort_order', si.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(iv.variant, iv.path)
              FROM core.image_variants iv
              WHERE iv.image_id = si.id
            ), '{}'::jsonb)
          )
          ORDER BY si.sort_order, si.created_at
        )
        FROM core.site_images si
        WHERE si.site_id = s.id
          AND si.pool = 'gallery'
          AND si.deleted_at IS NULL
          AND si.is_active = true
      ), '[]'::jsonb),
      'content_images', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', si.id,
            'alt_text', si.alt_text,
            'sort_order', si.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(iv.variant, iv.path)
              FROM core.image_variants iv
              WHERE iv.image_id = si.id
            ), '{}'::jsonb)
          )
          ORDER BY si.sort_order, si.created_at
        )
        FROM core.site_images si
        WHERE si.site_id = s.id
          AND si.pool = 'content'
          AND si.deleted_at IS NULL
          AND si.is_active = true
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
    ), '[]'::jsonb)
  ) as payload
FROM core.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN core.themes t ON t.id = s.theme_id
WHERE
  s.is_published = true
  AND p.status = 'active'
  AND p.deleted_at IS NULL;

COMMENT ON VIEW core.public_site_payload IS 'Complete public site payload with image pools from image_variants, no Supabase Storage references';

-- ============================================================
-- 3. Rebuild all_site_data view (remove legacy image columns)
-- ============================================================
CREATE OR REPLACE VIEW core.all_site_data AS
SELECT
    s.id AS site_id,
    s.subdomain,
    s.is_published,
    s.settings AS site_settings,
    s.created_at AS site_created_at,
    s.updated_at AS site_updated_at,
    t.id AS theme_id,
    t.key AS theme_key,
    t.name AS theme_name,
    t.config AS theme_config,
    p.id AS professional_id,
    p.handle AS professional_handle,
    p.display_name AS professional_display_name,
    p.bio AS professional_bio,
    p.location_street_address AS professional_location_street_address,
    p.location_city AS professional_location_city,
    p.location_state AS professional_location_state,
    p.location_postcode AS professional_location_postcode,
    p.location_country AS professional_location_country,
    COALESCE(
        jsonb_agg(
            jsonb_build_object(
                'id', b.id,
                'site_id', b.site_id,
                'professional_id', b.professional_id,
                'block_type', b.block_type,
                'block_group', b.block_group,
                'title', b.title,
                'url', b.url,
                'icon_key', b.icon_key,
                'sort_order', b.sort_order,
                'is_active', b.is_active,
                'settings', b.settings,
                'created_at', b.created_at,
                'updated_at', b.updated_at
            )
            ORDER BY b.sort_order
        ) FILTER (WHERE b.id IS NOT NULL),
        '[]'::jsonb
    ) AS blocks
FROM core.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN core.themes t ON t.id = s.theme_id
LEFT JOIN core.blocks b ON b.site_id = s.id
GROUP BY s.id, t.id, p.id;

-- ============================================================
-- 4. Drop enforce_site_gallery_max6 trigger + function
--    (replaced by advisory-lock pool limits in ProfessionalUploadController)
-- ============================================================
DROP TRIGGER IF EXISTS enforce_site_gallery_max6 ON core.site_images;
DROP FUNCTION IF EXISTS core.enforce_site_gallery_max6();

-- ============================================================
-- 5. Drop legacy image columns
-- ============================================================

-- professionals: icon_bucket, icon_path, headshot_bucket, headshot_path
ALTER TABLE core.professionals DROP CONSTRAINT IF EXISTS professionals_headshot_bucket_when_path;
ALTER TABLE core.professionals DROP CONSTRAINT IF EXISTS professionals_icon_bucket_when_path;
ALTER TABLE core.professionals DROP COLUMN IF EXISTS icon_bucket;
ALTER TABLE core.professionals DROP COLUMN IF EXISTS icon_path;
ALTER TABLE core.professionals DROP COLUMN IF EXISTS headshot_bucket;
ALTER TABLE core.professionals DROP COLUMN IF EXISTS headshot_path;

-- sites: banner_bucket, banner_path
ALTER TABLE core.sites DROP CONSTRAINT IF EXISTS sites_banner_bucket_when_path;
ALTER TABLE core.sites DROP COLUMN IF EXISTS banner_bucket;
ALTER TABLE core.sites DROP COLUMN IF EXISTS banner_path;

-- site_images: bucket (was pointing to Supabase bucket, now using image_variants disk/path)
ALTER TABLE core.site_images DROP COLUMN IF EXISTS bucket;
