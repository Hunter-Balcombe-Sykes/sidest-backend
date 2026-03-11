-- Ensure runtime role can access newly introduced schemas.
-- This keeps Laravel writes working when using a restricted DB role.

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    IF EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'billing') THEN
      EXECUTE 'GRANT USAGE ON SCHEMA billing TO app_backend';
      EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA billing TO app_backend';
      EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA billing TO app_backend';

      EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA billing GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
      EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA billing GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
    END IF;

    IF EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'retail') THEN
      EXECUTE 'GRANT USAGE ON SCHEMA retail TO app_backend';
      EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA retail TO app_backend';
      EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA retail TO app_backend';

      EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA retail GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
      EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA retail GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
    END IF;
  END IF;
END $$;
