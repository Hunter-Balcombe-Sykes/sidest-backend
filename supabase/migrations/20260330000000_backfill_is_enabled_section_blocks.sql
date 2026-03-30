-- Backfill is_enabled for section blocks based on actual requirements.
-- gallery  → requires at least 1 active, non-deleted gallery image
-- booking  → requires (active service) AND (booking integration OR booking_url)
-- all others have no requirements and remain is_enabled = true

-- Gallery: disable where site has no active gallery images
UPDATE core.blocks
SET    is_enabled = false
WHERE  block_group = 'sections'
  AND  block_type  = 'gallery'
  AND  NOT EXISTS (
      SELECT 1
      FROM   core.site_media sm
      WHERE  sm.site_id    = blocks.site_id
        AND  sm.pool       = 'gallery'
        AND  sm.media_type = 'image'
        AND  sm.is_active  = true
        AND  sm.deleted_at IS NULL
  );

-- Booking: disable where professional has no active service
-- OR no booking integration and no booking_url setting
UPDATE core.blocks
SET    is_enabled = false
WHERE  block_group = 'sections'
  AND  block_type  = 'booking'
  AND  NOT (
      -- must have at least one active service
      EXISTS (
          SELECT 1
          FROM   core.services s
          WHERE  s.professional_id = blocks.professional_id
            AND  s.is_active       = true
            AND  s.deleted_at      IS NULL
      )
      AND
      -- must have a booking integration OR a booking_url in settings
      (
          EXISTS (
              SELECT 1
              FROM   core.professional_integrations pi
              WHERE  pi.professional_id = blocks.professional_id
                AND  pi.provider        IN ('square', 'fresha')
          )
          OR
          NULLIF(BTRIM(blocks.settings->>'booking_url'), '') IS NOT NULL
      )
  );
