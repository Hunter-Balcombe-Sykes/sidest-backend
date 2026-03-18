-- Backfill media_type and processing_state for all existing site_images rows.
-- All rows that existed before this migration are images;
-- rows that have at least one image_variant are ready, otherwise pending.

-- Every existing row is an image.
UPDATE core.site_images
SET media_type = 'image'
WHERE media_type IS DISTINCT FROM 'image';

-- Rows that have been processed (variants exist) are ready.
UPDATE core.site_images si
SET processing_state = 'ready'
WHERE EXISTS (
    SELECT 1 FROM core.image_variants iv WHERE iv.image_id = si.id
)
AND processing_state IS DISTINCT FROM 'ready';

-- Rows without variants stay as 'pending' (the column default from Migration A).
-- These are images that were uploaded but whose processing job failed or is still
-- in-flight. They can be re-processed by dispatching ProcessImageVariantsJob again.
