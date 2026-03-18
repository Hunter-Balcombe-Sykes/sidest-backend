-- Replace Laravel-only migrations for brand affiliate invites and notification action fields.

BEGIN;

CREATE TABLE IF NOT EXISTS core.brand_affiliate_invites (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    token varchar(80) NOT NULL,
    status varchar(24) NOT NULL DEFAULT 'pending',
    invite_type varchar(24) NOT NULL DEFAULT 'generic',
    email varchar(255) NULL,
    email_lc varchar(255) NULL,
    phone varchar(40) NULL,
    first_name varchar(80) NULL,
    last_name varchar(80) NULL,
    message text NULL,
    claimed_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    accepted_at timestamptz NULL,
    expires_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE core.brand_affiliate_invites
    ADD COLUMN IF NOT EXISTS expires_at timestamptz NULL;

UPDATE core.brand_affiliate_invites
SET status = 'pending'
WHERE status IS NULL
   OR status NOT IN ('pending', 'accepted', 'declined', 'expired');

UPDATE core.brand_affiliate_invites
SET invite_type = 'generic'
WHERE invite_type IS NULL
   OR invite_type NOT IN ('generic', 'personalised');

ALTER TABLE core.brand_affiliate_invites
    DROP CONSTRAINT IF EXISTS brand_affiliate_invites_status_check;
ALTER TABLE core.brand_affiliate_invites
    ADD CONSTRAINT brand_affiliate_invites_status_check
    CHECK (status IN ('pending', 'accepted', 'declined', 'expired'));

ALTER TABLE core.brand_affiliate_invites
    DROP CONSTRAINT IF EXISTS brand_affiliate_invites_invite_type_check;
ALTER TABLE core.brand_affiliate_invites
    ADD CONSTRAINT brand_affiliate_invites_invite_type_check
    CHECK (invite_type IN ('generic', 'personalised'));

CREATE UNIQUE INDEX IF NOT EXISTS brand_affiliate_invites_token_uq
    ON core.brand_affiliate_invites (token);

CREATE INDEX IF NOT EXISTS brand_affiliate_invites_brand_status_idx
    ON core.brand_affiliate_invites (brand_professional_id, status);

CREATE INDEX IF NOT EXISTS brand_affiliate_invites_email_lc_idx
    ON core.brand_affiliate_invites (email_lc);

UPDATE core.brand_affiliate_invites
SET status = 'expired',
    updated_at = now()
WHERE status = 'pending'
  AND expires_at IS NOT NULL
  AND expires_at <= now();

WITH ranked_pending AS (
    SELECT
        id,
        ROW_NUMBER() OVER (
            PARTITION BY email_lc
            ORDER BY created_at DESC, id DESC
        ) AS row_num
    FROM core.brand_affiliate_invites
    WHERE status = 'pending'
      AND email_lc IS NOT NULL
)
UPDATE core.brand_affiliate_invites bai
SET status = 'expired',
    updated_at = now()
FROM ranked_pending rp
WHERE bai.id = rp.id
  AND rp.row_num > 1;

CREATE UNIQUE INDEX IF NOT EXISTS brand_affiliate_invites_pending_email_uq
    ON core.brand_affiliate_invites (email_lc)
    WHERE status = 'pending' AND email_lc IS NOT NULL;

ALTER TABLE core.notifications
    ADD COLUMN IF NOT EXISTS primary_action_label varchar(255) NULL;
ALTER TABLE core.notifications
    ADD COLUMN IF NOT EXISTS secondary_action_label varchar(255) NULL;
ALTER TABLE core.notifications
    ADD COLUMN IF NOT EXISTS secondary_action_url text NULL;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON core.brand_affiliate_invites TO app_backend';
  END IF;
END $$;

COMMIT;
