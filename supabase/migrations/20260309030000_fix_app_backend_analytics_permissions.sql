-- Ensure runtime role can write analytics events.
-- This keeps public pageview/click ingestion working under restricted DB roles.

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    IF EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'analytics') THEN
      EXECUTE 'GRANT USAGE ON SCHEMA analytics TO app_backend';
      EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA analytics TO app_backend';
      EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA analytics TO app_backend';

      EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA analytics GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
      EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA analytics GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
    END IF;
  END IF;
END $$;
