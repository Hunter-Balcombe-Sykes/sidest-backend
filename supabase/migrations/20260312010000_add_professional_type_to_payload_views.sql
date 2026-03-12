-- Expose professional_type in DB-backed payload views.

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'core'
          AND table_name = 'professionals'
          AND column_name = 'professional_type'
    ) THEN
        RAISE EXCEPTION 'core.professionals.professional_type is missing. Run 20260312000000_add_professional_type_to_professionals.sql first.';
    END IF;
END $$;

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
      'professional_type', p.professional_type,
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

COMMENT ON VIEW core.public_site_payload IS 'Complete public site payload with image pools, services, legal content, and professional_type';

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
    ) AS blocks,
    p.professional_type AS professional_type
FROM core.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN core.themes t ON t.id = s.theme_id
LEFT JOIN core.blocks b ON b.site_id = s.id
GROUP BY s.id, t.id, p.id;

COMMENT ON VIEW core.all_site_data IS 'Site + professional aggregate payload for staff tooling (includes professional_type)';
