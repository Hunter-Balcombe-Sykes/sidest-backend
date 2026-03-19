-- Store per-professional destructive-action confirmation overrides.
-- Used by frontend "Don't ask again" toggles for delete/unselect actions.

BEGIN;

CREATE TABLE IF NOT EXISTS core.professional_confirmation_preferences (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL
        REFERENCES core.professionals(id) ON DELETE CASCADE,
    action_key text NOT NULL,
    skip_confirmation boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT professional_confirmation_preferences_professional_action_uq
        UNIQUE (professional_id, action_key)
);

ALTER TABLE core.professional_confirmation_preferences OWNER TO postgres;

COMMENT ON TABLE core.professional_confirmation_preferences IS 'Per-professional overrides for destructive-action confirmation dialogs.';
COMMENT ON COLUMN core.professional_confirmation_preferences.action_key IS 'Action identifier (for example: delete_customer, delete_media, unselect_product).';
COMMENT ON COLUMN core.professional_confirmation_preferences.skip_confirmation IS 'When true, frontend can skip confirmation modal for this action.';

CREATE INDEX IF NOT EXISTS professional_confirmation_preferences_professional_idx
    ON core.professional_confirmation_preferences (professional_id);

CREATE OR REPLACE FUNCTION core.set_professional_confirmation_preferences_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_professional_confirmation_preferences_set_updated_at ON core.professional_confirmation_preferences;
CREATE TRIGGER trg_professional_confirmation_preferences_set_updated_at
BEFORE UPDATE ON core.professional_confirmation_preferences
FOR EACH ROW
EXECUTE FUNCTION core.set_professional_confirmation_preferences_updated_at();

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON core.professional_confirmation_preferences TO app_backend';
  END IF;
END $$;

COMMIT;
