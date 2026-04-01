BEGIN;

-- Add category column to existing notifications table
ALTER TABLE core.notifications
    ADD COLUMN IF NOT EXISTS category TEXT;

COMMENT ON COLUMN core.notifications.category
    IS 'Notification category key used for email preference routing (e.g. invites, commissions, payouts).';

-- Professional-controlled email preferences per category
CREATE TABLE IF NOT EXISTS core.notification_email_preferences (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id UUID NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    category_key    TEXT NOT NULL,
    enabled         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (professional_id, category_key)
);

-- Platform-controlled email policies per category (professional_id NULL = global)
CREATE TABLE IF NOT EXISTS core.notification_email_policies (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id UUID REFERENCES core.professionals(id) ON DELETE CASCADE,
    category_key    TEXT NOT NULL,
    mode            TEXT NOT NULL DEFAULT 'default' CHECK (mode IN ('default', 'force_on', 'force_off')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Partial unique indexes: NULL != NULL in standard unique constraints in PostgreSQL
CREATE UNIQUE INDEX IF NOT EXISTS uq_notif_email_policies_global
    ON core.notification_email_policies (category_key)
    WHERE professional_id IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_notif_email_policies_per_professional
    ON core.notification_email_policies (professional_id, category_key)
    WHERE professional_id IS NOT NULL;

COMMIT;
