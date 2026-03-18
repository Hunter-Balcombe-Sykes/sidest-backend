-- Extend core.site_images with columns needed to support video uploads alongside images.
-- All new columns default to values that keep existing image rows valid.

ALTER TABLE core.site_images
    ADD COLUMN IF NOT EXISTS media_type       varchar(10)  NOT NULL DEFAULT 'image',
    ADD COLUMN IF NOT EXISTS processing_state varchar(20)  NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS original_mime    varchar(100),
    ADD COLUMN IF NOT EXISTS original_size_bytes bigint,
    ADD COLUMN IF NOT EXISTS duration_ms      integer,
    ADD COLUMN IF NOT EXISTS poster_path      text,
    ADD COLUMN IF NOT EXISTS processing_error text;

ALTER TABLE core.site_images
    ADD CONSTRAINT site_images_media_type_check
        CHECK (media_type IN ('image', 'video')),
    ADD CONSTRAINT site_images_processing_state_check
        CHECK (processing_state IN ('pending', 'processing', 'ready', 'failed'));

COMMENT ON COLUMN core.site_images.media_type        IS 'Media type: image or video';
COMMENT ON COLUMN core.site_images.processing_state  IS 'Processing lifecycle: pending | processing | ready | failed';
COMMENT ON COLUMN core.site_images.original_mime     IS 'MIME type of the uploaded original file';
COMMENT ON COLUMN core.site_images.original_size_bytes IS 'File size of the uploaded original in bytes';
COMMENT ON COLUMN core.site_images.duration_ms       IS 'Video duration in milliseconds (null for images)';
COMMENT ON COLUMN core.site_images.poster_path       IS 'Storage path to the video poster image (null for images)';
COMMENT ON COLUMN core.site_images.processing_error  IS 'Error message when processing_state = failed';

-- Composite partial index for cap enforcement and listing queries scoped by media type.
CREATE INDEX IF NOT EXISTS si_pool_media_active
    ON core.site_images(site_id, pool, media_type, sort_order)
    WHERE deleted_at IS NULL AND is_active = true;
