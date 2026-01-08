-- 1) Drop redundant triggers (keep a single trigger per table that sets defaults)
DO $$
BEGIN
  -- core.email_subscriptions: keep set_email_subscription_defaults_insupd
  IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgname = 'set_email_subscription_defaults_trg'
      AND tgrelid = 'core.email_subscriptions'::regclass
  ) THEN
    EXECUTE 'DROP TRIGGER set_email_subscription_defaults_trg ON core.email_subscriptions';
  END IF;

  IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgname = 'trg_set_email_subscription_defaults_biur'
      AND tgrelid = 'core.email_subscriptions'::regclass
  ) THEN
    EXECUTE 'DROP TRIGGER trg_set_email_subscription_defaults_biur ON core.email_subscriptions';
  END IF;

  -- core.professionals: keep set_professional_defaults_insupd
  IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgname = 'set_professional_defaults_trg'
      AND tgrelid = 'core.professionals'::regclass
  ) THEN
    EXECUTE 'DROP TRIGGER set_professional_defaults_trg ON core.professionals';
  END IF;

  IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgname = 'trg_set_professional_defaults_biur'
      AND tgrelid = 'core.professionals'::regclass
  ) THEN
    EXECUTE 'DROP TRIGGER trg_set_professional_defaults_biur ON core.professionals';
  END IF;
END$$;

-- 2) Drop clearly-duplicated FKs (keep the *_id_fkey versions)
ALTER TABLE analytics.lead_submissions
  DROP CONSTRAINT IF EXISTS lead_submissions_customer_fk,
  DROP CONSTRAINT IF EXISTS lead_submissions_professional_fk,
  DROP CONSTRAINT IF EXISTS lead_submissions_site_fk;

ALTER TABLE analytics.link_clicks
  DROP CONSTRAINT IF EXISTS link_clicks_block_fk,
  DROP CONSTRAINT IF EXISTS link_clicks_professional_fk,
  DROP CONSTRAINT IF EXISTS link_clicks_site_fk;

ALTER TABLE analytics.site_visits
  DROP CONSTRAINT IF EXISTS site_visits_professional_fk,
  DROP CONSTRAINT IF EXISTS site_visits_site_fk;

-- 3) RLS policies for notifications & notification_receipts
-- Assumptions:
-- - Professionals map via core.professionals.auth_user_id = auth.uid()
-- - Staff are in core.comet_staff; admins have role='admin'
-- - Broadcast notifications have professional_id IS NULL

-- Ensure RLS is enabled (already true per report, but idempotent)
ALTER TABLE core.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE core.notification_receipts ENABLE ROW LEVEL SECURITY;

-- Notifications policies
DO $$
BEGIN
  -- Professionals: can select their own + broadcast
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notifications' AND policyname='notifications_select_pro'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notifications_select_pro ON core.notifications
      FOR SELECT
      USING (
        professional_id IS NULL
        OR professional_id IN (
          SELECT p.id FROM core.professionals p
          WHERE p.auth_user_id = auth.uid()
            AND p.deleted_at IS NULL
        )
      );
    $p$;
  END IF;

  -- Staff (any role): can select all
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notifications' AND policyname='notifications_select_staff'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notifications_select_staff ON core.notifications
      FOR SELECT
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
        )
      );
    $p$;
  END IF;

  -- Staff admin: insert/update/delete
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notifications' AND policyname='notifications_write_admin'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notifications_write_admin ON core.notifications
      FOR ALL
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
            AND cs.role = 'admin'
        )
      )
      WITH CHECK (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
            AND cs.role = 'admin'
        )
      );
    $p$;
  END IF;
END$$;

-- Notification receipts policies
DO $$
BEGIN
  -- Professionals: select/insert/update/delete their own receipts
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notification_receipts' AND policyname='notification_receipts_pro_all'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notification_receipts_pro_all ON core.notification_receipts
      FOR ALL
      USING (
        professional_id IN (
          SELECT p.id FROM core.professionals p
          WHERE p.auth_user_id = auth.uid()
            AND p.deleted_at IS NULL
        )
      )
      WITH CHECK (
        professional_id IN (
          SELECT p.id FROM core.professionals p
          WHERE p.auth_user_id = auth.uid()
            AND p.deleted_at IS NULL
        )
      );
    $p$;
  END IF;

  -- Staff (any role): can SELECT all receipts
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notification_receipts' AND policyname='notification_receipts_staff_select'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notification_receipts_staff_select ON core.notification_receipts
      FOR SELECT
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
        )
      );
    $p$;
  END IF;

  -- Staff admin: can write all receipts
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notification_receipts' AND policyname='notification_receipts_admin_write'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notification_receipts_admin_write ON core.notification_receipts
      FOR ALL
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
            AND cs.role = 'admin'
        )
      )
      WITH CHECK (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
            AND cs.role = 'admin'
        )
      );
    $p$;
  END IF;
END$$;