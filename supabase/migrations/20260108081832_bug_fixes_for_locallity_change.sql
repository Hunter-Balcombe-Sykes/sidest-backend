-- 1) Fix storage bucket default mismatch: point defaults to 'media'
ALTER TABLE core.professionals ALTER COLUMN icon_bucket   SET DEFAULT 'media';
ALTER TABLE core.professionals ALTER COLUMN headshot_bucket SET DEFAULT 'media';
ALTER TABLE core.sites         ALTER COLUMN banner_bucket SET DEFAULT 'media';
ALTER TABLE core.site_images   ALTER COLUMN bucket        SET DEFAULT 'media';

-- (Optional safety) Ensure the bucket exists (creates if missing)
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM storage.buckets WHERE id = 'media') THEN
    INSERT INTO storage.buckets (id, name, public, type) VALUES ('media', 'media', true, 'STANDARD')
    ON CONFLICT (id) DO NOTHING;
  END IF;
END$$;

-- 2) Soft delete compatibility for core.blocks unique constraints
DROP INDEX IF EXISTS core.blocks_links_site_group_sort_uq;
CREATE UNIQUE INDEX blocks_links_site_group_sort_uq
  ON core.blocks (site_id, block_group, sort_order)
  WHERE block_group = 'links' AND deleted_at IS NULL;

DROP INDEX IF EXISTS core.blocks_sections_site_group_sort_uq;
CREATE UNIQUE INDEX blocks_sections_site_group_sort_uq
  ON core.blocks (site_id, block_group, sort_order)
  WHERE block_group = 'sections' AND deleted_at IS NULL;

DROP INDEX IF EXISTS core.blocks_sections_site_group_type_uq;
CREATE UNIQUE INDEX blocks_sections_site_group_type_uq
  ON core.blocks (site_id, block_group, block_type)
  WHERE block_group = 'sections' AND deleted_at IS NULL;

-- 3) RLS: restrict hard deletes on core.blocks to staff/admin only
-- Remove existing DELETE policy (professional-or-staff)
DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname = 'core' AND tablename = 'blocks' AND policyname = 'link_blocks_delete_authenticated'
  ) THEN
    EXECUTE 'DROP POLICY link_blocks_delete_authenticated ON core.blocks';
  END IF;
END$$;

-- Add staff-only delete policy
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname = 'core' AND tablename = 'blocks' AND policyname = 'link_blocks_delete_staff'
  ) THEN
    EXECUTE $POLICY$
      CREATE POLICY link_blocks_delete_staff
      ON core.blocks
      FOR DELETE
      TO authenticated
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
        )
      );
    $POLICY$;
  END IF;
END$$;

-- 4) RLS: allow inserting lead submissions (e.g., from public site)
-- Create an INSERT policy for analytics.lead_submissions
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname = 'analytics' AND tablename = 'lead_submissions' AND policyname = 'lead_submissions_insert_public'
  ) THEN
    EXECUTE $POLICY$
      CREATE POLICY lead_submissions_insert_public
      ON analytics.lead_submissions
      FOR INSERT
      TO anon, authenticated
      WITH CHECK (true); -- add stricter checks if needed (e.g., validate site_id/professional_id)
    $POLICY$;
  END IF;
END$$;

-- (Optional) also allow readback if desired:
CREATE POLICY lead_submissions_select_staff ON analytics.lead_submissions
   FOR SELECT TO authenticated
   USING (EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid()));

-- 5) (Optional helpers for NOT NULL columns with NULL defaults)
-- If you want DB-level safeguards for lowercase + token fields, uncomment and adjust:
-- UPDATE core.email_subscriptions SET email_lc = lower(email) WHERE email_lc IS NULL AND email IS NOT NULL;
-- UPDATE core.email_subscriptions SET unsubscribe_token = gen_random_uuid()::text WHERE unsubscribe_token IS NULL;
-- ALTER TABLE core.email_subscriptions ALTER COLUMN email_lc SET DEFAULT lower(email);
-- (Better: add BEFORE INSERT/UPDATE triggers to compute these consistently.)