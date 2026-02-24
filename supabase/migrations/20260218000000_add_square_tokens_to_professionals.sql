-- Add Square OAuth tokens to professionals table
ALTER TABLE core.professionals
ADD COLUMN square_access_token text COLLATE "C" NULL,
ADD COLUMN square_refresh_token text COLLATE "C" NULL,
ADD COLUMN square_merchant_id varchar(255) COLLATE "C" NULL,
ADD COLUMN square_expires_at timestamp with time zone COLLATE "C" NULL;

COMMENT ON COLUMN core.professionals.square_access_token IS 'Encrypted Square OAuth access token';
COMMENT ON COLUMN core.professionals.square_refresh_token IS 'Encrypted Square OAuth refresh token';
COMMENT ON COLUMN core.professionals.square_merchant_id IS 'Square merchant ID (merchant scope)';
COMMENT ON COLUMN core.professionals.square_expires_at IS 'Timestamp when Square access token expires';
