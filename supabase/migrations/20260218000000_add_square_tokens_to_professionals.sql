-- Add Square OAuth tokens to professionals table (idempotent)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'square_access_token') THEN
        ALTER TABLE core.professionals ADD COLUMN square_access_token text COLLATE "C" NULL;
        COMMENT ON COLUMN core.professionals.square_access_token IS 'Encrypted Square OAuth access token';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'square_refresh_token') THEN
        ALTER TABLE core.professionals ADD COLUMN square_refresh_token text COLLATE "C" NULL;
        COMMENT ON COLUMN core.professionals.square_refresh_token IS 'Encrypted Square OAuth refresh token';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'square_merchant_id') THEN
        ALTER TABLE core.professionals ADD COLUMN square_merchant_id varchar(255) COLLATE "C" NULL;
        COMMENT ON COLUMN core.professionals.square_merchant_id IS 'Square merchant ID (merchant scope)';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'core' AND table_name = 'professionals' AND column_name = 'square_expires_at') THEN
        ALTER TABLE core.professionals ADD COLUMN square_expires_at timestamp with time zone NULL;
        COMMENT ON COLUMN core.professionals.square_expires_at IS 'Timestamp when Square access token expires';
    END IF;
END $$;
