-- Create core.media_variants to store video artifact rows (MP4, HLS playlists, poster).
-- Image variants continue to use core.image_variants; this table is video-only.
-- Per-segment HLS files are stored on disk but NOT tracked here – only the playlist/
-- manifest rows, MP4 rows, and poster row are recorded (one row per logical artifact).

CREATE TABLE IF NOT EXISTS core.media_variants (
    id               uuid         PRIMARY KEY DEFAULT gen_random_uuid(),
    media_id         uuid         NOT NULL REFERENCES core.site_images(id) ON DELETE CASCADE,
    variant_key      varchar(40)  NOT NULL,   -- 'optimized' | 'maximized' | 'adaptive' | 'poster'
    artifact_type    varchar(20)  NOT NULL,   -- 'mp4' | 'hls_playlist' | 'poster'
    disk             varchar(40)  NOT NULL DEFAULT 'media',
    path             text         NOT NULL,   -- storage path to the artifact file or playlist
    mime             varchar(100),            -- MIME type of the artifact
    width            integer,                 -- video width px (null for audio-only or poster)
    height           integer,                 -- video height px
    bitrate_kbps     integer,                 -- target bitrate for this rendition
    file_size_bytes  bigint,                  -- file size in bytes (null for playlists that grow)
    duration_ms      integer,                 -- duration in ms (null for master playlists)
    metadata         jsonb,                   -- arbitrary extra data (codec info, ffprobe output, etc.)
    created_at       timestamp with time zone NOT NULL DEFAULT now(),
    updated_at       timestamp with time zone NOT NULL DEFAULT now()
);

ALTER TABLE core.media_variants OWNER TO postgres;

COMMENT ON TABLE  core.media_variants              IS 'Video artifact variants (MP4, HLS playlists, poster) for each site_image with media_type=video';
COMMENT ON COLUMN core.media_variants.variant_key  IS 'Logical tier: optimized | maximized | adaptive | poster';
COMMENT ON COLUMN core.media_variants.artifact_type IS 'Physical format: mp4 | hls_playlist | poster';
COMMENT ON COLUMN core.media_variants.path         IS 'Storage path on the media disk (not a public URL)';
COMMENT ON COLUMN core.media_variants.metadata     IS 'Arbitrary codec/probe metadata; not included in public payload';

-- One artifact per (media item, variant tier, artifact type).
-- variant_key 'optimized' can appear for both 'mp4' and 'hls_playlist',
-- so the unique constraint spans all three columns.
CREATE UNIQUE INDEX IF NOT EXISTS mv_media_variant_artifact
    ON core.media_variants(media_id, variant_key, artifact_type);

CREATE INDEX IF NOT EXISTS mv_media_id
    ON core.media_variants(media_id);

CREATE INDEX IF NOT EXISTS mv_media_artifact_type
    ON core.media_variants(media_id, artifact_type);

-- Keep updated_at current.
CREATE OR REPLACE FUNCTION core.set_media_variants_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_media_variants_set_updated_at ON core.media_variants;
CREATE TRIGGER trg_media_variants_set_updated_at
BEFORE UPDATE ON core.media_variants
FOR EACH ROW
EXECUTE FUNCTION core.set_media_variants_updated_at();
