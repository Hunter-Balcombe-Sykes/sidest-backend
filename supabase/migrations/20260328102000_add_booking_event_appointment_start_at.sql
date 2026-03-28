BEGIN;

ALTER TABLE analytics.booking_events
    ADD COLUMN IF NOT EXISTS appointment_start_at timestamptz NULL;

CREATE INDEX IF NOT EXISTS booking_events_professional_appointment_idx
    ON analytics.booking_events (professional_id, appointment_start_at DESC);

COMMIT;
